function aWorkflowConstructor()
{
  var areas;
  var action;
  var setMode;
  var initialized = false;
  var toolbar;
  var self = this;
  var mode;

  // Buttons
  var draft;
  var apply;
  var applied;

  this.setup = function(options)
  {
    // Don't reinitialize on AJAX updates
    if (initialized)
    {
      return;
    }
    mode = options.mode;
    areas = [];
    initialized = true;
    action = options.action;
    aLog('Binding aAfterJsCalls');

    var controlsInitialized = false;
    $('body').bind('aAfterJsCalls.aWorkflowToolbar', function() {
      var toolbar = $('<div class="a-ui a-workflow-toolbar clearfix><ul class="a-ui a-controls clearfix"></ul></div>');

      $('.a-global-toolbar').after(toolbar);
      // Where to redirect back to (the browser knows best)
      setMode = apostrophe.addParameterToUrl(options.setModeUrl, 'url', document.location.href);

      //If we're in draft mode, apply a class of 'draft' to the toolbar to allow for bettert CSS scoping:
      (mode == 'draft' ? $('.a-ui.a-workflow-toolbar').addClass('draft') : $('.a-ui.a-workflow-toolbar').addClass('applied') );

      draft = button('draft', 'Draft', mode === 'draft', apostrophe.addParameterToUrl(setMode, 'mode', 'draft'));

      if ((mode === 'draft') && (options.canApply))
      {
       //Add the "Apply" button to the toolbar
        apply = button('apply', 'Apply Changes', false, '#');
        apply.click(function() {
          self.sync();
          return false;
        });
      }
      applied = button('applied', 'Public', mode === 'applied', apostrophe.addParameterToUrl(setMode, 'mode', 'applied'));

      modeAlert = '<li class="a-workflow-toolbar-alert">'+(mode == 'draft' ? 'You are currently in draft mode. Changes you make will not be public until applied.' : 'You are currently in public mode. This is the website as it exists in its published state.')+'</li>';
      toolbar.append(modeAlert);

      // Set up the toolbar only once
      $('body').unbind('aAfterJsCalls.aWorkflowToolbar');

      /**
       * Create a button such as Draft, Apply or Applied. Show a busy indicator when clicked.
       * Draft and applied load new pages so they don't need to clear this, but you can clear it
       * by calling unbusy(button) as I do for the 'apply' button when the sync finishes
       */
      function button(name, label, current, href)
      {
        var b = $('<li class="' + (current ? 'a-workflow-current' : '') + ' a-workflow-toolbar-' + name + '"><a class="a-btn big alt a-busy">' + label + '<span class="icon"></span></a></li>');
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
      document.location.href = apostrophe.addParameterToUrl(setMode, 'mode', 'applied');
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