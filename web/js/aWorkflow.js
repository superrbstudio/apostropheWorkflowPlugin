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

      /**
       * Create a button such as Draft, Publish or Published. Show a busy indicator when clicked.
       * Draft and published load new pages so they don't need to clear this, but you can clear it
       * by calling unbusy(button) as I do for the 'publish' button when the sync finishes
       */
      function button(name, label, current, href)
      {
        var b = $('<li class="' + (current ? 'a-workflow-current' : '') + ' a-workflow-toolbar-' + name + '"><a class="a-btn alt no-bg a-busy">' + label + '<span class="icon"></span></a></li>');
        b.find('a').attr('href', href);
        toolbar.append(b);
        b.click(function() {
          busy(b);
          return true;
        });
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
      unbusy(publish);
      alert('Content synced. Switch to the "published" view to verify.');
    });
  };

  function busy(button)
  {
    button.find('a').addClass('icon').addClass('a-busy');
    button.find('a').addClass('a-busy');
  }

  function unbusy(button)
  {
    button.find('a').removeClass('icon').removeClass('a-busy');
    button.find('a').removeClass('a-busy');
  }
}

var aWorkflow = new aWorkflowConstructor();