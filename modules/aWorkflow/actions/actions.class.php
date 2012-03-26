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
        $draftPageId = $area['id'];
        $name = $area['name'];
        $editable = $area['editable'];
        if ($editable)
        {
          $draftPage = aPageTable::retrieveByIdWithSlots($draftPageId);
          if (preg_match('/^aWorkflowDraftFor:(\d+)$/', $draftPage['slug'], $matches))
          {
            $pageId = $matches[1];
            $page = aPageTable::retrieveByIdWithSlots($pageId);
            if ($page && $draftPage)
            {
              error_log("Calling syncTo for " . $page['id'] . ',' . $draftPage['id'] . ',' . $name);
              $draftPage->syncTo($page, $name);
            }
          }
        }
      }
    }
    echo(json_encode(array('status' => 'ok')));
    exit(0);
  }
}