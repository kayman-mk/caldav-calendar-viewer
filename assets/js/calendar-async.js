(function () {
    'use strict';

    function fetchCalendar(feedId, nonce, containerId) {
        var container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = '<div class="cdcv-loading">Loading calendar…</div>';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cdcvAsyncCalendar.ajax_url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            var debugInfo = '';
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.debug) {
                    debugInfo = '<pre class="cdcv-debug">' + JSON.stringify(resp.debug, null, 2) + '</pre>';
                }
                if (xhr.status === 200) {
                    if (resp.success) {
                        container.innerHTML = resp.data.html + debugInfo;
                    } else {
                        container.innerHTML = '<div class="cdcv-error">' + (resp.data && resp.data.message ? resp.data.message : 'Unable to load calendar z.') + '</div>' + debugInfo;
                    }
                } else {
                    container.innerHTML = '<div class="cdcv-error">Unable to load calendar x.</div>' + debugInfo;
                }
            } catch (e) {
                container.innerHTML = '<div class="cdcv-error">Unable to load calendar v.<br>Status: ' + xhr.status + '<br>Response: <pre>' + xhr.responseText + '</pre></div>' + debugInfo;
            }
        };
        xhr.onerror = function () {
            container.innerHTML = '<div class="cdcv-error">Unable to load calendar a.<br>Status: ' + xhr.status + '<br>Response: <pre>' + xhr.responseText + '</pre></div>';
        };
        xhr.send('action=cdcv_get_calendar&feed_id=' + encodeURIComponent(feedId) + '&nonce=' + encodeURIComponent(nonce));
    }

    function initAsyncCalendars() {
        var els = document.querySelectorAll('.cdcv-calendar-async');
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            var feedId = el.getAttribute('data-feed-id');
            var nonce = el.getAttribute('data-nonce');
            var containerId = el.id;
            if (feedId && nonce && containerId) {
                fetchCalendar(feedId, nonce, containerId);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAsyncCalendars);
    } else {
        initAsyncCalendars();
    }
})();
