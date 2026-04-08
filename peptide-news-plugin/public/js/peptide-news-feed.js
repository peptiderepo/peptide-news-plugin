/**
 * Peptide News Feed — React Frontend
 *
 * A clean, accessible news feed component that displays peptide research
 * articles with title, summary, source, date, and keyword tags.
 *
 * @since 2.0.0
 */
const {
  useState,
  useEffect,
  useCallback,
  useRef,
  memo
} = React;

/* ── Constants ──────────────────────────────────────────────────────── */

const ARTICLES_PER_PAGE = 10;
const CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutes

/* ── Utilities ──────────────────────────────────────────────────────── */

/**
 * Format an ISO date string to a human-readable form.
 *
 * @param {string} dateStr ISO date string.
 * @returns {string} Formatted date (e.g., "Apr 5, 2026").
 */
function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return dateStr;
  return d.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric'
  });
}

/**
 * Calculate a human-readable relative time string.
 *
 * @param {string} dateStr ISO date string.
 * @returns {string} Relative time (e.g., "2 hours ago").
 */
function timeAgo(dateStr) {
  if (!dateStr) return '';
  const now = Date.now();
  const then = new Date(dateStr).getTime();
  if (isNaN(then)) return '';
  const diffMs = now - then;
  const diffMin = Math.floor(diffMs / 60000);
  if (diffMin < 1) return 'Just now';
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHr = Math.floor(diffMin / 60);
  if (diffHr < 24) return `${diffHr}h ago`;
  const diffDay = Math.floor(diffHr / 24);
  if (diffDay < 7) return `${diffDay}d ago`;
  return formatDate(dateStr);
}

/* ── Simple in-memory cache ─────────────────────────────────────────── */

const apiCache = new Map();
function getCached(key) {
  const entry = apiCache.get(key);
  if (!entry) return null;
  if (Date.now() - entry.ts > CACHE_TTL_MS) {
    apiCache.delete(key);
    return null;
  }
  return entry.data;
}
function setCache(key, data) {
  apiCache.set(key, {
    data,
    ts: Date.now()
  });
}

/* ── API Layer ──────────────────────────────────────────────────────── */

/**
 * Fetch articles from the WP REST API with caching.
 *
 * @param {string} restUrl  Base REST URL.
 * @param {number} page     Page number.
 * @param {number} count    Articles per page.
 * @param {AbortSignal} signal  Abort signal for cleanup.
 * @returns {Promise<Object>} Response data.
 */
async function fetchArticles(restUrl, page, count, signal) {
  const cacheKey = `${restUrl}:${page}:${count}`;
  const cached = getCached(cacheKey);
  if (cached) return cached;
  const url = `${restUrl}articles?count=${count}&page=${page}`;
  const resp = await fetch(url, {
    signal
  });
  if (!resp.ok) {
    throw new Error(`HTTP ${resp.status}: ${resp.statusText}`);
  }
  const data = await resp.json();
  setCache(cacheKey, data);
  return data;
}

/* ── Click Tracking ─────────────────────────────────────────────────── */

/**
 * Track an outbound click via sendBeacon or fallback XHR.
 *
 * @param {number} articleId
 */
function trackClick(articleId) {
  const config = window.peptideNewsFeed || {};
  if (!config.ajaxUrl || !config.nonce) return;
  const formData = new FormData();
  formData.append('action', 'peptide_news_track_click');
  formData.append('nonce', config.nonce);
  formData.append('article_id', articleId);
  formData.append('referrer', document.referrer || '');
  formData.append('page_url', window.location.href);
  formData.append('session_id', config.sessionId || '');
  if (navigator.sendBeacon) {
    navigator.sendBeacon(config.ajaxUrl, formData);
  } else {
    fetch(config.ajaxUrl, {
      method: 'POST',
      body: formData
    }).catch(() => {});
  }
}

/* ── Components ─────────────────────────────────────────────────────── */

/**
 * Single article card.
 */
const ArticleCard = memo(function ArticleCard({
  article
}) {
  const summary = article.ai_summary || article.excerpt || '';
  const handleClick = useCallback(() => {
    trackClick(article.id);
  }, [article.id]);
  return /*#__PURE__*/React.createElement("article", {
    className: "pn-article",
    role: "article"
  }, /*#__PURE__*/React.createElement("div", {
    className: "pn-article-meta"
  }, /*#__PURE__*/React.createElement("span", {
    className: "pn-source"
  }, article.source), /*#__PURE__*/React.createElement("span", {
    className: "pn-separator",
    "aria-hidden": "true"
  }, "\xB7"), /*#__PURE__*/React.createElement("time", {
    className: "pn-date",
    dateTime: article.published_at,
    title: formatDate(article.published_at)
  }, timeAgo(article.published_at))), /*#__PURE__*/React.createElement("h3", {
    className: "pn-article-title"
  }, /*#__PURE__*/React.createElement("a", {
    href: article.source_url,
    onClick: handleClick,
    target: "_blank",
    rel: "noopener noreferrer"
  }, article.title)), summary && /*#__PURE__*/React.createElement("p", {
    className: "pn-article-excerpt"
  }, summary), article.author && /*#__PURE__*/React.createElement("span", {
    className: "pn-author"
  }, article.author));
});

/**
 * Loading skeleton placeholder.
 */
function LoadingSkeleton({
  count = 3
}) {
  return /*#__PURE__*/React.createElement("div", {
    className: "pn-loading",
    "aria-busy": "true",
    "aria-label": "Loading articles"
  }, Array.from({
    length: count
  }, (_, i) => /*#__PURE__*/React.createElement("div", {
    key: i,
    className: "pn-skeleton-card"
  }, /*#__PURE__*/React.createElement("div", {
    className: "pn-skeleton-line pn-skeleton-meta"
  }), /*#__PURE__*/React.createElement("div", {
    className: "pn-skeleton-line pn-skeleton-title"
  }), /*#__PURE__*/React.createElement("div", {
    className: "pn-skeleton-line pn-skeleton-excerpt"
  }), /*#__PURE__*/React.createElement("div", {
    className: "pn-skeleton-line pn-skeleton-excerpt-short"
  }))));
}

/**
 * Error state with retry.
 */
function ErrorMessage({
  message,
  onRetry
}) {
  return /*#__PURE__*/React.createElement("div", {
    className: "pn-error",
    role: "alert"
  }, /*#__PURE__*/React.createElement("p", null, "Unable to load articles. ", message), /*#__PURE__*/React.createElement("button", {
    className: "pn-retry-btn",
    onClick: onRetry,
    type: "button"
  }, "Try Again"));
}

/**
 * Empty state.
 */
function EmptyState() {
  return /*#__PURE__*/React.createElement("div", {
    className: "pn-empty"
  }, /*#__PURE__*/React.createElement("p", null, "No peptide news articles available yet. Check back soon!"));
}

/**
 * Pagination controls.
 */
const Pagination = memo(function Pagination({
  page,
  totalPages,
  onPageChange
}) {
  if (totalPages <= 1) return null;
  return /*#__PURE__*/React.createElement("nav", {
    className: "pn-pagination",
    "aria-label": "Article pages"
  }, /*#__PURE__*/React.createElement("button", {
    className: "pn-page-btn",
    onClick: () => onPageChange(page - 1),
    disabled: page <= 1,
    "aria-label": "Previous page",
    type: "button"
  }, "\u2190 Newer"), /*#__PURE__*/React.createElement("span", {
    className: "pn-page-info",
    "aria-current": "page"
  }, "Page ", page, " of ", totalPages), /*#__PURE__*/React.createElement("button", {
    className: "pn-page-btn",
    onClick: () => onPageChange(page + 1),
    disabled: page >= totalPages,
    "aria-label": "Next page",
    type: "button"
  }, "Older \u2192"));
});

/* ── Main Feed Component ────────────────────────────────────────────── */

/**
 * PeptideNewsFeed — root component.
 *
 * Reads configuration from window.peptideNewsFeed which is
 * localized by the PHP shortcode renderer.
 */
function PeptideNewsFeed() {
  const config = window.peptideNewsFeed || {};
  const restUrl = config.restUrl || '/wp-json/peptide-news/v1/';
  const perPage = config.count || ARTICLES_PER_PAGE;
  const [articles, setArticles] = useState([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const feedRef = useRef(null);
  const loadArticles = useCallback(async (pageNum, signal) => {
    setLoading(true);
    setError(null);
    try {
      const data = await fetchArticles(restUrl, pageNum, perPage, signal);
      setArticles(data.articles || []);
      setTotalPages(data.total_pages || 1);
    } catch (err) {
      if (err.name !== 'AbortError') {
        setError(err.message || 'Something went wrong.');
      }
    } finally {
      setLoading(false);
    }
  }, [restUrl, perPage]);
  useEffect(() => {
    const controller = new AbortController();
    loadArticles(page, controller.signal);
    return () => controller.abort();
  }, [page, loadArticles]);
  const handlePageChange = useCallback(newPage => {
    setPage(newPage);
    // Scroll feed container into view on pagination.
    if (feedRef.current) {
      feedRef.current.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }
  }, []);
  const handleRetry = useCallback(() => {
    loadArticles(page);
  }, [page, loadArticles]);
  return /*#__PURE__*/React.createElement("div", {
    className: "pn-news-feed",
    ref: feedRef
  }, loading && /*#__PURE__*/React.createElement(LoadingSkeleton, {
    count: Math.min(perPage, 5)
  }), !loading && error && /*#__PURE__*/React.createElement(ErrorMessage, {
    message: error,
    onRetry: handleRetry
  }), !loading && !error && articles.length === 0 && /*#__PURE__*/React.createElement(EmptyState, null), !loading && !error && articles.length > 0 && /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
    className: "pn-articles-list",
    role: "feed",
    "aria-label": "Peptide news articles"
  }, articles.map(article => /*#__PURE__*/React.createElement(ArticleCard, {
    key: article.id,
    article: article
  }))), /*#__PURE__*/React.createElement(Pagination, {
    page: page,
    totalPages: totalPages,
    onPageChange: handlePageChange
  })));
}

/* ── Mount ──────────────────────────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', function () {
  const containers = document.querySelectorAll('[data-peptide-news-feed]');
  containers.forEach(function (el) {
    const root = ReactDOM.createRoot(el);
    root.render(React.createElement(PeptideNewsFeed));
  });
});
