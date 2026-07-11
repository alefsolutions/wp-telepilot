document.addEventListener('DOMContentLoaded', function () {
  var tabs = document.querySelectorAll('.telepress-tab');
  var panels = document.querySelectorAll('.telepress-tab-panel');

  if (!tabs.length || !panels.length) {
    return;
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      var target = tab.getAttribute('data-tab');

      tabs.forEach(function (item) {
        item.classList.toggle('is-active', item === tab);
      });

      panels.forEach(function (panel) {
        panel.classList.toggle('is-active', panel.getAttribute('data-panel') === target);
      });
    });
  });
});
