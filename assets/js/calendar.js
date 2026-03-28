/**
 * CalDav Calendar Viewer – Front-end JavaScript.
 *
 * Adds interactive tooltips for calendar events on hover.
 */
(function () {
    'use strict';

    /** Create and position a tooltip element near the hovered event. */
    function showTooltip(event) {
        var title = event.currentTarget.getAttribute('title');
        if (!title) {
            return;
        }

        // Prevent default browser tooltip.
        event.currentTarget.dataset.cdcvTitle = title;
        event.currentTarget.removeAttribute('title');

        var tooltip = document.createElement('div');
        tooltip.className = 'cdcv-tooltip';
        tooltip.textContent = title;
        document.body.appendChild(tooltip);

        var rect = event.currentTarget.getBoundingClientRect();
        tooltip.style.top = (window.scrollY + rect.bottom + 6) + 'px';
        tooltip.style.left = (window.scrollX + rect.left) + 'px';

        event.currentTarget._cdcvTooltip = tooltip;
    }

    /** Remove the tooltip element. */
    function hideTooltip(event) {
        var el = event.currentTarget;
        if (el._cdcvTooltip) {
            el._cdcvTooltip.remove();
            delete el._cdcvTooltip;
        }
        // Restore title attribute.
        if (el.dataset.cdcvTitle) {
            el.setAttribute('title', el.dataset.cdcvTitle);
            delete el.dataset.cdcvTitle;
        }
    }

    /** Bind listeners once the DOM is ready. */
    function init() {
        var events = document.querySelectorAll('.cdcv-event[title]');
        for (var i = 0; i < events.length; i++) {
            events[i].addEventListener('mouseenter', showTooltip);
            events[i].addEventListener('mouseleave', hideTooltip);
        }

        // Inject tooltip styles once.
        if (!document.getElementById('cdcv-tooltip-style')) {
            var style = document.createElement('style');
            style.id = 'cdcv-tooltip-style';
            style.textContent =
                '.cdcv-tooltip {' +
                '  position: absolute;' +
                '  z-index: 100000;' +
                '  max-width: 280px;' +
                '  padding: 8px 12px;' +
                '  background: #1d2327;' +
                '  color: #fff;' +
                '  font-size: 0.8rem;' +
                '  line-height: 1.4;' +
                '  border-radius: 4px;' +
                '  white-space: pre-wrap;' +
                '  pointer-events: none;' +
                '  box-shadow: 0 2px 8px rgba(0,0,0,.25);' +
                '}';
            document.head.appendChild(style);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

