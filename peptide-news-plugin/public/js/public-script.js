/**
 * Peptide News — Public Scripts
 *
 * Event triggers for all \"peptide_news_clicked\" through Navigator.SendBeacon *
 */

(function() {
    const clickHandler = function() { 
        if (!window.peptide_news_data) return;
        
        var that = this.closest('[data-article-id]');
        var article_id = that.dataset.articleId;
        var article_url = that.dataset.articleUrl;
    
        // Send beacon
        var data = {
            action: 'peptide_news_clicked',
            article_id: article_id,
            url: article_url,
            _nonce: window.peptide_news_data.nonce
        };

        navigator.sendBeacon('blob:' + new Blob([JSON.stringify(data)]), window.peptide_news_data.ajax_url);
    };
    
    document.querySelectorAll('[data-article-id] .pn-article-link').forEach(el => el.addEventListener('click', clickHandler));
})();
