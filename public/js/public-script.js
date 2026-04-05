/**
 * Peptide News — Public Click Tracker
 *
 * Tracks outbound clicks on article links via AJAX and
 * manages visitor session cookies.
 *
 * @since 1.0.0
 */
(function ($) {
    'use strict';

    // Set session cookie if it doesn't exist.
    function initSession() {
        if (!getCookie('pn_session_id')) {
            var sessionId = peptideNewsPublic.session_id || generateUUID();
            setCookie('pn_session_id', sessionId, 30); // 30-minute cookie
        }
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    function setCookie(name, value, minutes) {
        var d = new Date();
        d.setTime(d.getTime() + minutes * 60 * 1000);
        var cookie = name + '=' + encodeURIComponent(value) +
            ';expires=' + d.toUTCString() +
            ';path=/;SameSite=Lax';
        if (window.location.protocol === 'https:') {
            cookie += ';Secure';
        }
        document.cookie = cookie;
    }

    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    // Track click via AJAX (fire-and-forget with beacon fallback).
    function trackClick(articleId) {
        var data = {
            action: 'peptide_news_track_click',
            nonce: peptideNewsPublic.nonce,
            article_id: articleId,
            referrer: document.referrer || '',
            page_url: window.location.href,
            session_id: getCookie('pn_session_id') || '',
        };

        // Try sendBeacon first (non-blocking, survives page navigation).
        if (navigator.sendBeacon) {
            var formData = new FormData();
            Object.keys(data).forEach(function (key) {
                formData.append(key, data[key]);
            });
            navigator.sendBeacon(peptideNewsPublic.ajax_url, formData);
            return;
        }

        // Fallback to AJAX.
        $.post(peptideNewsPublic.ajax_url, data);
    }

    $(document).ready(function () {
        initSession();

        // Delegate click events on tracked links.
        $(document).on('click', '.pn-track-click', function () {
            var articleId = $(this).data('article-id');
            if (articleId) {
                trackClick(articleId);
            }
            // Don't prevent default — let the link open normally.
        });
    });

})(jQuery);
