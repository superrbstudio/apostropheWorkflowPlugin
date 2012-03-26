function aWorkflowConstructor()
{
  var areas;
  var action;
  var initialized = false;
  var toolbar;
  var self = this;
  var state = 'draft';

  this.setup = function(options)
  {
    // Don't reinitialize on AJAX updates
    if (initialized)
    {
      return;
    }
    state = options.state;
    areas = [];
    initialized = true;
    action = options.action;
    aLog('Binding aAfterJsCalls');

    var controlsInitialized = false;
    $('body').bind('aAfterJsCalls.aWorkflowToolbar', function() {
      var toolbar = $('<div class="a-ui a-workflow-toolbar clearfix><ul class="a-ui a-controls clearfix"></ul></div>');
      $('.a-global-toolbar').after(toolbar);
      var draft = button('draft', 'Draft', state === 'draft', apostrophe.addParameterToUrl(document.location.href, 'view-published', 0));
      if (state === 'draft')
      {
        publish = button('publish', 'Publish', false, '#');
        publish.click(function() {
          self.sync();
          return false;
        });
      }
      var published = button('published', 'Published', state === 'published', apostrophe.addParameterToUrl(document.location.href, 'view-published', 1));
      // Set up the toolbar only once
      $('body').unbind('aAfterJsCalls.aWorkflowToolbar');
      function button(name, label, current, href)
      {
        var b = $('<li class="' + (current ? 'a-workflow-current' : '') + ' a-workflow-toolbar-' + name + '"><a class="a-btn alt no-bg">' + label + '</a></li>');
        $(b).find('a').attr('href', href);
        toolbar.append(b);
        return b;
      }
    });
  };

  this.registerArea = function(options)
  {
    var i;
    for (i = 0; (i < areas.length); i++)
    {
      if ((areas[i].id === options.id) && (areas[i].name === options.name))
      {
        return;
      }
    }
    areas.push(options);
  };

  this.sync = function()
  {
    $.post(action, $.param({ 'areas': areas }), function() {
      // Obviously this is not the final UI
      alert('Content synced. Refresh in a logged-out browser to verify.');
    });
  };

}

var aWorkflow = new aWorkflowConstructor();