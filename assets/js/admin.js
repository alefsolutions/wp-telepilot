document.addEventListener('DOMContentLoaded', function () {
  var tabs = document.querySelectorAll('.telepilot-tab');
  var panels = document.querySelectorAll('.telepilot-tab-panel');
  var secretInput = document.querySelector('[data-telepilot-webhook-secret]');
  var generateButton = document.querySelector('[data-telepilot-generate-secret]');
  var copyButton = document.querySelector('[data-telepilot-copy-secret]');

  if (tabs.length && panels.length) {
    tabs[0].parentElement.setAttribute('role', 'tablist');
    tabs[0].parentElement.setAttribute('aria-label', 'WP Telepilot settings');
    tabs.forEach(function (tab, index) {
      var name = tab.getAttribute('data-tab');
      tab.id = 'telepilot-tab-' + name;
      tab.setAttribute('role', 'tab');
      tab.setAttribute('aria-controls', 'telepilot-panel-' + name);
      tab.setAttribute('aria-selected', tab.classList.contains('is-active') ? 'true' : 'false');
      tab.tabIndex = tab.classList.contains('is-active') ? 0 : -1;
      tab.addEventListener('keydown', function (event) {
        var next;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = tabs.length - 1;
        if (next !== undefined) { event.preventDefault(); tabs[next].click(); tabs[next].focus(); }
      });
      tab.addEventListener('click', function () {
        var target = tab.getAttribute('data-tab');

        tabs.forEach(function (item) {
          item.classList.toggle('is-active', item === tab);
          item.setAttribute('aria-selected', item === tab ? 'true' : 'false');
          item.tabIndex = item === tab ? 0 : -1;
        });

        panels.forEach(function (panel) {
          panel.classList.toggle('is-active', panel.getAttribute('data-panel') === target);
          panel.hidden = panel.getAttribute('data-panel') !== target;
        });
      });
    });
    panels.forEach(function (panel) {
      var name = panel.getAttribute('data-panel');
      panel.id = 'telepilot-panel-' + name;
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', 'telepilot-tab-' + name);
      panel.hidden = !panel.classList.contains('is-active');
    });
    // Return to the provider controls after submitting the separate test form.
    tabs.forEach(function (tab) {
      if (window.location.hash === '#telepilot-panel-' + tab.getAttribute('data-tab')) {
        tab.click();
      }
    });
  }

  if (secretInput && generateButton) {
    generateButton.addEventListener('click', function () {
      try {
        secretInput.value = generateSecret(32);
      } catch (error) {
        var notice = document.getElementById('telepilot-secret-error');
        if (!notice) {
          notice = document.createElement('p');
          notice.id = 'telepilot-secret-error';
          notice.setAttribute('role', 'alert');
          generateButton.parentElement.appendChild(notice);
        }
        notice.textContent = error.message;
        return;
      }
      secretInput.focus();
      secretInput.select();
    });
  }

  if (secretInput && copyButton) {
    copyButton.addEventListener('click', function () {
      var defaultLabel = copyButton.getAttribute('data-copy-label') || 'Copy Secret';
      var copiedLabel = copyButton.getAttribute('data-copied-label') || 'Copied';

      copyText(secretInput.value)
        .then(function () {
          copyButton.textContent = copiedLabel;

          window.setTimeout(function () {
            copyButton.textContent = defaultLabel;
          }, 1400);
        })
        .catch(function () {
          secretInput.focus();
          secretInput.select();
        });
    });
  }
});

function generateSecret(length) {
  var alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  var cryptoObject = window.crypto || window.msCrypto;
  var output = '';
  var index;

  if (cryptoObject && typeof cryptoObject.getRandomValues === 'function') {
    var randomValues = new Uint32Array(length);
    cryptoObject.getRandomValues(randomValues);

    for (index = 0; index < length; index += 1) {
      output += alphabet.charAt(randomValues[index] % alphabet.length);
    }

    return output;
  }

  throw new Error('Secure randomness requires a supported browser and HTTPS.');
}

function copyText(value) {
  if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
    return navigator.clipboard.writeText(value);
  }

  return new Promise(function (resolve, reject) {
    var input = document.createElement('textarea');

    input.value = value;
    input.setAttribute('readonly', 'readonly');
    input.style.position = 'absolute';
    input.style.left = '-9999px';
    document.body.appendChild(input);
    input.select();

    try {
      if (document.execCommand('copy')) {
        resolve();
      } else {
        reject(new Error('Copy command was not accepted.'));
      }
    } catch (error) {
      reject(error);
    } finally {
      document.body.removeChild(input);
    }
  });
}
