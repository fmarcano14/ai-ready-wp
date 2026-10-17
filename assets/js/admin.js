(function () {
  var form = document.getElementById("ai-ready-wp-settings");
  var preview = document.getElementById("ai-ready-wp-preview");
  var status = document.getElementById("ai-ready-wp-preview-status");
  var copy = document.getElementById("ai-ready-wp-copy");
  if (!form || !preview || !status || !window.aiReadyWp) {
    return;
  }

  var timer = null;

  function refresh() {
    var data = new window.FormData(form);
    data.append("action", "ai_ready_wp_preview");
    data.append("_ajax_nonce", window.aiReadyWp.nonce);
    status.textContent = window.aiReadyWp.i18n.loading;
    window
      .fetch(window.aiReadyWp.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: data,
      })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.success || !payload.data) {
          status.textContent = window.aiReadyWp.i18n.error;
          return;
        }
        preview.textContent = payload.data.markdown || "";
        status.textContent = window.aiReadyWp.i18n.ready;
      })
      .catch(function () {
        status.textContent = window.aiReadyWp.i18n.error;
      });
  }

  function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(refresh, 400);
  }

  form.addEventListener("input", schedule);
  form.addEventListener("change", schedule);

  if (copy) {
    copy.addEventListener("click", function () {
      var text = preview.textContent || "";
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          status.textContent = window.aiReadyWp.i18n.copied;
        });
        return;
      }
      status.textContent = window.aiReadyWp.i18n.error;
    });
  }
})();
