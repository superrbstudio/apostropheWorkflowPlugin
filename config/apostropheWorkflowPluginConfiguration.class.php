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
      $this->dispatcher->connect('a.filterVersionJoin', array($this, 'filterVersionJoin'));
      $this->dispatcher->connect('a.filterPageCheckPrivilege', array($this, 'filterPageCheckPrivilege'));
      $this->dispatcher->connect('a.afterAreaComponent', array($this, 'afterAreaComponent'));
      $this->dispatcher->connect('a.migrateSchemaAdditions', array($this, 'migrate'));
      $this->dispatcher->connect('a.filterNewPage', array($this, 'filterNewPage'));
      $this->dispatcher->connect('a.filterPageSettingsForm', array($this, 'filterPageSettingsForm'));
      $this->dispatcher->connect('a.filterNextVersion', array($this, 'filterNextVersion'));
      $this->dispatcher->connect('a.filterSetLatestVersion', array($this, 'filterSetLatestVersion'));
      $this->dispatcher->connect('a.filterAreaEditable', array($this, 'filterAreaEditable'));
      self::$registered = true;
    }
  }

  /**
   * Standard Apostrophe tracks the latest version. Also track the latest draft version.
   * Note that we show whichever is greater in draft mode, which allows us to use the workflow
   * plugin right away in an existing project without seeming to lose existing content. It also
   * protects us from problems if there is project level or plugin code that doesn't know about
   * draft mode and which also updates latest_version
   */

  public function migrate($event)
  {
    $migrate = $event->getSubject();
    if (!$migrate->columnExists('a_area', 'draft_version'))
    {
      $migrate->sql(array('ALTER TABLE a_area ADD COLUMN draft_version BIGINT DEFAULT NULL'));
    }
  }

  /**
   * Returns true if this user should be able to apply changes so they appear to the public.
   * By default they must have the admin credential. Filter the aWorkflow.filterCanApply 
   * event to alter that at project level or in another plugin
   */
  public function canApply()
  {
    $result = sfContext::getInstance()->getUser()->hasCredential('admin');
    $event = new sfEvent($this, 'aWorkflow.filterCanApply', array());
    sfContext::getInstance()->getEventDispatcher()->filter($event, $result);
    return $event->getReturnValue();

  }

  /**
   * Replace the standard version join with one that pays attention to draft versions,
   * if we are in draft mode. We want the newer of latest_version and draft_version. Also
   * make sure we pull the one that is non-null, if any.
   */
  public function filterVersionJoin($event, $versionJoin)
  {    
    $mode = $this->getMode();
    if ((!$event['version']) && ($mode === 'draft'))
    {
      // Rebuild the join if we are in draft mode and not pulling an explicit version number
      $versionJoin = array();
      $versionJoin['args'] = array();
      $versionJoin['clauses'] = array();
      $versionJoin['clauses'][] = 'a.AreaVersions v';
      // We want the newest version 
      // Use COALESCE to avoid always matching the thing that's NULL (that's just how GREATEST rolls).
      // Use GREATEST rather than MAX because we are comparing two columns in the same row, which is
      // not what MAX is for (MAX is for aggregation over many rows)
      $versionJoin['clauses'][] = 'WITH GREATEST(COALESCE(a.latest_version, 0), COALESCE (a.draft_version, 0)) = v.version';
    }
    return $versionJoin;
  }

  /**
   * If you don't have the apply privilege then you can't delete or manage a published page either as that
   * would affect what is visible to the end user too. See also a.filterAreaEditable - we don't have to 
   * address the 'edit' privilege here
   * 
   */
  public function filterPageCheckPrivilege($event, $result)
  {
    $pageInfo = $event['pageInfo'];
    if (in_array($event['privilege'], array('delete', 'manage')) && (!$pageInfo['archived']) && (!$this->canApply()))
    {
      return false;
    }
    return $result;
  }

  protected $jsSetup = false;

  /**
   * Register each editable area so that javascript can request that all the editable areas' changes be applied.
   * Also initialize the client-side javascript (just once).
   */
  public function afterAreaComponent($event)
  {
    if (!$this->jsSetup)
    {
      aTools::$jsCalls[] = array('callable' => 'aWorkflow.setup(?)', 'args' => array(array(
        'action' => sfContext::getInstance()->getController()->genUrl('@a_workflow_publish'),
        // draft or published: what we are seeing now (we can't edit until we leave published mode)
        'mode' => $this->getMode(),
        // Privilege of applying changes so they become publicly visible
        'canApply' => $this->canApply(),
        'setModeUrl' => sfContext::getInstance()->getController()->genUrl('@a_workflow_set_mode')
        )));
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
    if (!$this->canApply())
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
    if (!$this->canApply())
    {
      if (isset($form['archived']))
      {
        unset($form['archived']);
      }
    }
    return $form;
  }

  /**
   * We need a version number for a new aAreaVersion. Normally it's area.latest_version + 1, but
   * the presence of draft versions can change that
   */
  public function filterNextVersion($event, $n)
  {
    return  max($event['area']['latest_version'], $event['area']['draft_version']) + 1;
  }

  /**
   * We need to store the latest verion number in the $area object. Normally
   * area.latest_version is set to the version property of the new aAreaVersion object,
   * but in draft mode we want to set area.draft_version instead. For this event we 
   * modify $event['area'] directly and return true to signify that we don't want the
   * default behavior. This design means we don't have to figure out how to
   * undo the default behavior
   */
  public function filterSetLatestVersion($event, $overridden)
  {
    if ($this->getMode() === 'draft')
    {
      $event['area']['draft_version'] = $event['version'];
      $overridden = true;
    }
    return $overridden;
  }

  /**
   * This filter lets us override the editable of a slot or area just like
   * the 'edit' option would, but is applied after that option so our decision
   * is final
   */
  public function filterAreaEditable($event, $editable)
  {
    if ($this->getMode() === 'applied')
    {
      $editable = false;
    }
    return $editable;
  }

  /**
   * Are we in draft mode (see the latest edits and add more edits) or applied mode
   * (see the live content, don't edit directly)?
   */
  public function getMode()
  {
    $user = sfContext::getInstance()->getUser();
    if (aTools::isPotentialEditor($user))
    {
      // It is critical to default to applied mode so that admin users created for tasks, etc.
      // do the right thing and directly create a new latest version of each page
      return sfContext::getInstance()->getUser()->getAttribute('mode', 'applied', 'aWorkflow');
    }
    // Noneditors and logged out users always see the applied content
    return 'applied';
  }
}
