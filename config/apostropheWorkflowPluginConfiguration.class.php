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
      return;
    }
    $this->globalSetupActive = true;
    $newSlug = false;
    $page = null;
    error_log("Pushing");
    if (aTools::isPotentialEditor())
    {
      error_log("isPotentialEditor");
      $page = aTools::getCurrentPage();
      $slug = $page->getSlug();
      error_log("For slug $slug");
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
      error_log("Popping");
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
    error_log("Entering with " . $this->inPageCheckPrivilege);
    // Someone tell me why ++ doesn't work here please
    $this->inPageCheckPrivilege = $this->inPageCheckPrivilege + 1;
    error_log("Then " . $this->inPageCheckPrivilege);
    // Let our recursive queries to find out the privileges of the original page work
    if ($this->inPageCheckPrivilege > 1)
    {
      error_log("Passthrough");
      $this->inPageCheckPrivilege = $this->inPageCheckPrivilege - 1;
      return $result;
    }
    $this->inPageCheckPrivilege = true;
    $privilege = $event['privilege'];
    $pageInfo = $event['pageInfo'];
    $user = $event['user'];
    error_log("pageCheckPrivilege ${pageInfo['id']} $privilege");
    if (count($this->pageStateStack) && (preg_match('/^aWorkflowDraftFor:(\d+)$/', $pageInfo['slug'], $matches)))
    {
      $pageId = $matches[1];
      error_log("Checking a draft");
      // The real page object of interest should be on the stack
      for ($i = count($this->pageStateStack) - 1; ($i >= 0); $i--)
      {
        $loopPageInfo = $this->pageStateStack[$i]['page'];
        if ($loopPageInfo['id'] === $pageId)
        {
          $result = Doctrine::getTable('aPage')->checkUserPrivilege($privilege, $loopPageInfo, $user);
          error_log("Result for draft is $result");
          $this->inPageCheckPrivilege = $this->inPageCheckPrivilege - 1;
          return $result;
        }
      }
    }
    else if (!in_array($privilege, array('view', 'view_custom')))
    {
      // You can't edit a non-draft page directly
      error_log("Flunking direct edit");
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
