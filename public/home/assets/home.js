(function () {
  var primaryEntry = document.getElementById('primaryEntry');
  var installState = document.getElementById('installState');

  function setState(installed) {
    if (installed) {
      primaryEntry.href = '/mer';
      primaryEntry.textContent = '进入商户后台';
      installState.textContent = '系统状态：已安装';
      return;
    }

    primaryEntry.href = '/install';
    primaryEntry.textContent = '开始安装';
    installState.textContent = '系统状态：未安装';
  }

  fetch('/adminapi/install/status', {
    headers: { Accept: 'application/json' },
    cache: 'no-store'
  })
    .then(function (response) {
      if (!response.ok) throw new Error('status failed');
      return response.json();
    })
    .then(function (res) {
      setState(Boolean(res && res.data && res.data.installed));
    })
    .catch(function () {
      installState.textContent = '系统状态：可进入后台或安装向导';
    });
})();
