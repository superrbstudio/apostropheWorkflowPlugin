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
      $this->dispatcher->connect('a.pageCheckPrivilege', array($this, 'pageCheckPrivilege'));
      $this->dispatcher->connect('a.afterAreaComponent', array($this, 'afterAreaComponent'));
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
  public function pageCheckPrivilege($event, $result)
  {
    // Someone tell me why ++ doesn't work here please
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
    else if (!in_array($privilege, array('view', 'view_custom')))
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
}
