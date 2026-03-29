(function () {
    'use strict';

    function fetchCalendar(feedId, nonce, containerId, label) {
        var container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = '<div class="cdcv-loading">Loading calendar…</div>';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cdcvAsyncCalendar.ajax_url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (xhr.status === 200) {
                    if (resp.success) {
                        container.innerHTML = resp.data.html;
                    } else {
                        container.innerHTML = '<div class="cdcv-error">' + (resp.data && resp.data.message ? resp.data.message : 'Unable to load calendar.') + '</div>';
                    }
                } else {
                    container.innerHTML = '<div class="cdcv-error">Unable to load calendar.</div>';
                }
            } catch (e) {
                container.innerHTML = '<div class="cdcv-error">Unable to load calendar.<br>Status: ' + xhr.status + '</div>';
            }
        };
        xhr.onerror = function () {
            container.innerHTML = '<div class="cdcv-error">Unable to load calendar.<br>Status: ' + xhr.status + '</div>';
        };
        var body = 'action=cdcv_get_calendar&feed_id=' + encodeURIComponent(feedId) + '&nonce=' + encodeURIComponent(nonce);
        if (label) {
            body += '&label=' + encodeURIComponent(label);
        }
        xhr.send(body);
    }

    function initAsyncCalendars() {
        var els = document.querySelectorAll('.cdcv-calendar-async');
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            var feedId = el.getAttribute('data-feed-id');
            var nonce = el.getAttribute('data-nonce');
            var containerId = el.id;
            var label = el.getAttribute('data-label') || '';
            if (feedId && nonce && containerId) {
                fetchCalendar(feedId, nonce, containerId, label);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAsyncCalendars);
    } else {
        initAsyncCalendars();
    }
})();
