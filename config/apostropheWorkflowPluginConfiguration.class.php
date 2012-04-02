<?php

class apostropheWorkflowPluginConfiguration extends sfPluginConfiguration
{
  static $registered = false;
  /**
   * @see sfPluginConfiguration
   */
  public function initialize()
  {
    // Yes, this can get called twice. This is Fabien's workaround:
    // http://trac.symfony-project.org/ticket/8026
    
    if (!self::$registered)
    {
      $this->dispatcher->connect('a.afterGlobalSetup', array($this, 'afterGlobalSetup'));
      $this->dispatcher->connect('a.afterGlobalShutdown', array($this, 'afterGlobalShutdown'));
      $this->dispatcher->connect('a.filterPageCheckPrivilege', array($this, 'filterPageCheckPrivilege'));
      $this->dispatcher->connect('a.afterAreaComponent', array($this, 'afterAreaComponent'));
      $this->dispatcher->connect('a.filterNewPage', array($this, 'filterNewPage'));
      $this->dispatcher->connect('a.filterPageSettingsForm', array($this, 'filterPageSettingsForm'));
      $this->dispatcher->connect('a.filterValidAndEditable', array($this, 'filterValidAndEditable'));
      self::$registered = true;
    }
  }

  protected $pageStateStack = array();

  /**
   * Recursion guards
   */
  protected $globalSetupActive = false;
  protected $globalShutdownActive = false;
  
  /**
   * Returns true if this user should be able to publish content. By default they must have
   * the admin credential. Filter the aWorkflow.filterPublishPrivilege event to alter that
   * at project level or in another plugin
   */
  public function hasPublishPrivilege()
  {
    $result = sfContext::getInstance()->getUser()->hasCredential('admin');
    $event = new sfEvent($this, 'aWorkflow.filterPublishPrivilege', array());
    sfContext::getInstance()->getEventDispatcher()->filter($event, $result);
    return $event->getReturnValue();

  }

  /**
   * Listen for newly completed aTools::globalSetup calls and push a virtual page 
   * as a substitute for any regular page (so everyone edits drafts, not the published versions).
   * Keep a stack of the original pages Push 'false' on the stack if we didn't make a change
   * so we can determine whether we need to make an extra globalShutdown() call
   */
  public function afterGlobalSetup($event)
  {
    if ($this->globalSetupActive)
    {
      return;
    }
    if (sfContext::getInstance()->getRequest()->getParameter('view-published'))
    {
      // error_log("View is published so not doing anything interesting");
      return;
    }
    $this->globalSetupActive = true;
    $newSlug = false;
    $page = null;
    if (aTools::isPotentialEditor() || (isset($options['edit']) && $options['edit']))
    {
      $page = aTools::getCurrentPage();
      $slug = $page->getSlug();
      $newSlug = 'aWorkflowDraftFor:' . $page->getId();
      aTools::globalSetup(array('slug' => $newSlug, 'aWorkflowDraftPush' => true));
    }
    $this->globalSetupActive = false;
    $this->pageStateStack[] = array('slug' => $newSlug, 'page' => $page);
  }

  /**
   * Make the extra globalShutdown call to remove any 
   * workflow virtual pa[ges from the stack
   */
  public function afterGlobalShutdown($event)
  {
    if ($this->globalShutdownActive)
    {
      return;
    }
    if (sfContext::getInstance()->getRequest()->getParameter('view-published'))
    {
      return;
    }
    $this->globalShutdownActive = true;
    if (count($this->pageStateStack))
    {
      $pageInfo = array_shift($this->pageStateStack);
      if ($pageInfo['slug'] !== false)
      {
        aTools::globalShutdown();
      }
    }
    $this->globalShutdownActive = false;
  }

  protected $inPageCheckPrivilege = 0;

  /**
   * If Apostrophe tries to check privileges on a workflow draft, check the
   * privileges of the actual page backing it instead
   */
  public function filterPageCheckPrivilege($event, $result)
  {
    // If a page is published and you don't have the privilege of publishing edits, then you
    // can't delete that page either
    if (($event['privilege'] === 'delete') && (!$pageInfo['archived']) && (!$this->hasPublishPrivilege()))
    {
      return false;
    }
    // Someone tell me why ++ doesn't work here please (really, it doesn't after the first nesting level; I did tests)
    $this->inPageCheckPrivilege = $this->inPageCheckPrivilege + 1;
    // Let our recursive queries to find out the privileges of the original page work
    if ($this->inPageCheckPrivilege > 1)
    {
      $this->inPageCheckPrivilege = $this->inPageCheckPrivilege - 1;
      return $result;
    }
    $this->inPageCheckPrivilege = true;
    $privilege = $event['privilege'];
    $pageInfo = $event['pageInfo'];
    $user = $event['user'];
    if (preg_match('/^aWorkflowDraftFor:(\d+)$/', $pageInfo['slug'], $matches))
    {
      $pageId = $matches[1];
      $pageOfInterest = null;
      // The real page object of interest may be on the stack already, avoid a redundant query
      $i = count($this->pageStateStack) - 1;
      while ($i >= 0)
      {
        $loopPageInfo = $this->pageStateStack[$i]['page'];
        if ($loopPageInfo['id'] === $pageId)
        {
          $pageOfInterest = $loopPageInfo;
          break;
        }
        $i--;
      }
      if (!$pageOfInterest)
      {
        // If we're saving a choice of image or performing some other action that's
        // not a conventional edit view save action then the page object might not
        // be on the stack already, so go get it. getInfo() won't cut it because
        // it's build on getPagesInfo which returns only pages this user can see
        // via the normal scheme of page tree permissions. 
        $pagesOfInterest = Doctrine::getTable('aPage')->createQuery('p')->where('p.id = ?', $pageId)->fetchArray();
        if (count($pagesOfInterest))
        {
          $pageOfInterest = $pagesOfInterest[0];
        }
      }
      if ($pageOfInterest)
      {
        // If an explicit 'edit' option was used, we don't have to check the privileges
        // on the real page, although it's good that we made sure there was one as a sanity check
        if (isset($event['edit']))
        {
          $result = $event['edit'];
        }
        else
        {
          $result = Doctrine::getTable('aPage')->checkUserPrivilege($privilege, $pageOfInterest, $user);
        }
        $this->inPageCheckPrivilege = $this->inPageCheckPrivilege - 1;
        return $result;
      }
      return false;
    }
    /**
     * Careful, don't block the 'manage' privilege, there
     * is no workflow for changing page permissions
     */
    else if (!in_array($privilege, array('view', 'view_custom', 'manage')))
    {
      // You can't edit a non-draft page directly bucko, I don't care if
      // you passed the 'edit' => true flag or not
      $this->inPageCheckPrivilege = $this->inPageCheckPrivilege - 1;
      return false;
    }
    else
    {
      $this->inPageCheckPrivilege = $this->inPageCheckPrivilege - 1;
      return $result;
    }
  }

  protected $jsSetup = false;

  /**
   * This runs in the context of a partial
   */
  public function afterAreaComponent($event)
  {
    // Only admins can publish, so don't show this bar to other users
    if (!sfContext::getInstance()->getUser()->hasCredential('admin'))
    {
      return;
    }
    if (!$this->jsSetup)
    {
      aTools::$jsCalls[] = array('callable' => 'aWorkflow.setup(?)', 'args' => array(array(
        'action' => sfContext::getInstance()->getController()->genUrl('@a_workflow_publish'),
        'state' => sfContext::getInstance()->getRequest()->getParameter('view-published') ? 'published' : 'draft')));
      $this->jsSetup = true;
    }
    aTools::$jsCalls[] = array('callable' => 'aWorkflow.registerArea(?)', 
      'args' => array(array('id' => $event['page']['id'], 'name' => $event['name'], 'editable' => $event['editable'], 'infinite' => $event['infinite'])));
  }

  /**
   * Make sure pages are born unpublished unless we're an admin
   */
  public function filterNewPage($event, $page)
  {
    if (!sfContext::getInstance()->getUser()->hasCredential('admin'))
    {
      $page->setArchived(true);
    }
    return $page;
  }


  /**
   * Remove the ability to publish or unpublish the page unless we're an admin
   */
  public function filterPageSettingsForm($event, $form)
  {
    if (!sfContext::getInstance()->getUser()->hasCredential('admin'))
    {
      unset($form['archived']);
    }
    return $form;
  }

  /**
   * The 'a' module calls a validAndEditable() method to make sure the user has
   * privileges to open page settings for a page and perform similar operations.
   * Our intercept of the filterPageCheckPrivilege event frustrates that.
   * Work around it by using the inPageCheckPrivilege flag to simulate a recursive
   * call; in such situations our event handler backs off and returns the
   * unaltered result for the page
   */
  public function filterValidAndEditable($event, $result)
  {
    // Please don't change this to ++, I reproduced a bug with that somehow!
    $this->inPageCheckPrivilege = $this->inPageCheckPrivilege + 1;
    $result = $event->getSubject()->userHasPrivilege($event['privilege']);
    $this->inPageCheckPrivilege = $this->inPageCheckPrivilege - 1;
    aTools::globalShutdown();
    return $result;
  }
}
