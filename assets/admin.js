/* Agent Ready — Admin JS */
(function ($) {
  'use strict';

  $(document).ready(function () {
    // Auto-dismiss notices after 4 seconds.
    setTimeout(function () {
      $('.notice.is-dismissible').fadeOut(400);
    }, 4000);

    // Confirm before regenerating AI page if it exists.
    $('form').on('submit', function (e) {
      var action = $(this).find('input[name="action"]').val();
      if (action === 'agent_ready_create_ai_page') {
        var btn = $(this).find('[type="submit"]');
        if (btn.text().indexOf('Regenerate') !== -1) {
          if (!window.confirm('This will overwrite the current /ai/ page content. Continue?')) {
            e.preventDefault();
          }
        }
      }
    });

    // Copy API URL to clipboard when clicking the code element.
    $('table.widefat code').css('cursor', 'pointer').attr('title', 'Click to copy').on('click', function () {
      var text = $(this).text();
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function () {
          showToast('Copied to clipboard!');
        });
      }
    });
  });

  function showToast(message) {
    var $toast = $('<div>')
      .text(message)
      .css({
        position: 'fixed',
        bottom: '24px',
        right: '24px',
        background: '#6366f1',
        color: '#fff',
        padding: '10px 20px',
        borderRadius: '8px',
        fontSize: '14px',
        zIndex: 99999,
        opacity: 0,
      })
      .appendTo('body')
      .animate({ opacity: 1 }, 200);

    setTimeout(function () {
      $toast.animate({ opacity: 0 }, 300, function () {
        $toast.remove();
      });
    }, 2000);
  }
})(jQuery);
