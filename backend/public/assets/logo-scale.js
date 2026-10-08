// Applies the organisation's sidebar logo width (admin/Settings → Branding) to #sidebarLogo.
// The value comes from GET /api/v1/auth/me (settings.sidebar_logo_width) via assets/app.js, which
// sets window.__morenaLogoWidth and calls applyMorenaLogo(); the poll covers the first render.
(function(){
  function apply(){
    var el = document.getElementById('sidebarLogo');
    if (!el || !window.__morenaLogoWidth) return false;
    el.style.width = window.__morenaLogoWidth + 'px';
    return true;
  }
  window.applyMorenaLogo = apply;
  var tries = 0;
  var iv = setInterval(function(){
    tries++;
    if (apply() || tries > 100) clearInterval(iv);
  }, 100);
})();
