(function () {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-doc-tab]'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('[data-doc-panel]'));
  var toc = document.querySelector('[data-doc-toc]');

  function renderToc(name) {
    if (!toc) {
      return;
    }
    var panel = document.querySelector('[data-doc-panel="' + name + '"]');
    var headings = panel ? Array.prototype.slice.call(panel.querySelectorAll('h2')) : [];
    toc.innerHTML = '';
    headings.forEach(function (heading) {
      if (!heading.id) {
        return;
      }
      var link = document.createElement('a');
      link.href = '#' + heading.id;
      link.textContent = heading.textContent;
      toc.appendChild(link);
    });
  }

  function activateDoc(name) {
    tabs.forEach(function (tab) {
      tab.classList.toggle('active', tab.dataset.docTab === name);
    });
    panels.forEach(function (panel) {
      panel.classList.toggle('active', panel.dataset.docPanel === name);
    });
    renderToc(name);
  }

  function scrollToDoc(name) {
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () {
        var panel = document.querySelector('[data-doc-panel="' + name + '"]');
        if (panel) {
          panel.scrollIntoView({ block: 'start' });
        }
      });
    });
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      activateDoc(tab.dataset.docTab);
      if (history.replaceState) {
        history.replaceState(null, '', '#' + tab.dataset.docTab);
      }
    });
  });

  var hash = window.location.hash.replace('#', '');
  if (hash === 'v1' || hash === 'v2') {
    activateDoc(hash);
    scrollToDoc(hash);
  } else {
    renderToc('v2');
  }

  window.addEventListener('hashchange', function () {
    var nextHash = window.location.hash.replace('#', '');
    if (nextHash === 'v1' || nextHash === 'v2') {
      activateDoc(nextHash);
      scrollToDoc(nextHash);
    }
  });

  document.querySelectorAll('.markdown table').forEach(function (table) {
    if (table.parentElement && table.parentElement.classList.contains('table-wrap')) {
      return;
    }
    var wrap = document.createElement('div');
    wrap.className = 'table-wrap';
    table.parentNode.insertBefore(wrap, table);
    wrap.appendChild(table);
  });

  document.querySelectorAll('.markdown pre').forEach(function (pre) {
    var code = pre.querySelector('code');
    if (!code) {
      return;
    }
    var button = document.createElement('button');
    button.className = 'copy-btn';
    button.type = 'button';
    button.textContent = '复制';
    button.addEventListener('click', function () {
      navigator.clipboard.writeText(code.innerText).then(function () {
        button.textContent = '已复制';
        window.setTimeout(function () {
          button.textContent = '复制';
        }, 1200);
      });
    });
    pre.appendChild(button);
  });
})();
