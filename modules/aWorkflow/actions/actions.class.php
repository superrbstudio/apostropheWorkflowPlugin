<?php

class aWorkflowActions extends sfActions
{
  public function executePublish(sfWebRequest $request)
  {
    $this->forward404Unless($this->getUser()->hasCredential('admin'));
    $areas = $request->getParameter('areas');
    if (is_array($areas))
    {
      foreach ($areas as $area)
      {
        $pageId = $area['id'];
        $name = $area['name'];
        $editable = $area['editable'];
        if ($editable)
        {
          $area = aAreaTable::retrieveOrCreateByPageIdAndName($pageId, $name);
          if (!$area->isNew())
          {
            // Can the latest version be newer than the draft version? Definitely, because
            // we work from the latest version by default in draft mode if it is newer,
            // either because this site just migrated to the plugin or because it has been
            // updated by code that isn't savvy to the plugin
            $area->latest_version = max($area->draft_version, $area->latest_version);
            $area->save();
          }
        }
      }
    }
    echo(json_encode(array('status' => 'ok')));
    exit(0);
  }
  public function executeSetMode(sfWebRequest $request)
  {
    $mode = $request->getParameter('mode');
    if (!in_array($mode, array('draft', 'applied')))
    {
      $this->forward404();
    }
    $this->getUser()->setAttribute('mode', $mode, 'aWorkflow');
    return $this->redirect($request->getParameter('url'));
  }
}