(function(){
  function apply(){
    var el = document.getElementById('sidebarLogo');
    if (!el) return false;
    var w = localStorage.getItem('morena_logo_width') || '170';
    el.style.width = w + 'px';
    return true;
  }
  var tries = 0;
  var iv = setInterval(function(){
    tries++;
    if (apply() || tries > 100) clearInterval(iv);
  }, 100);
  window.addEventListener('storage', apply);
})();
