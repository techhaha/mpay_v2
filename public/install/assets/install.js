(function () {
  var step = 0;
  var titles = [
    ['协议说明', '请阅读并确认安装、支付业务和数据安全相关说明。'],
    ['环境检测', '安装前请确认关键运行环境全部通过。'],
    ['基础配置', '填写站点、数据库、Redis 和初始管理员信息。'],
    ['执行安装', '系统将写入配置、执行迁移、初始化数据并生成密钥。'],
    ['安装完成', '系统已完成初始化，可以进入管理后台。']
  ];

  var panels = document.querySelectorAll('.panel');
  var steps = document.querySelectorAll('.steps li');
  var nextBtn = document.getElementById('nextBtn');
  var prevBtn = document.getElementById('prevBtn');
  var toastMask = document.getElementById('toastMask');
  var toast = document.getElementById('toast');
  var form = document.getElementById('installForm');
  var installLog = document.getElementById('installLog');
  var progressBar = document.getElementById('progressBar');

  function initDefaults() {
    var siteUrl = form.querySelector('[name="site_url"]');
    if (siteUrl && !siteUrl.value) {
      siteUrl.value = window.location.origin;
    }
  }

  function apiUrl(path) {
    if (/^https?:\/\//i.test(path)) {
      return path;
    }
    var prefix = '';
    var installIndex = window.location.pathname.indexOf('/install');
    if (installIndex > 0) {
      prefix = window.location.pathname.slice(0, installIndex);
    }
    if (path.charAt(0) === '/') {
      return window.location.origin + prefix + path;
    }
    return window.location.origin + prefix + '/' + path;
  }

  function api(url, options) {
    return fetch(apiUrl(url), Object.assign({
      headers: { 'Content-Type': 'application/json' }
    }, options || {})).then(function (res) {
      return res.text().then(function (text) {
        try {
          return JSON.parse(text);
        } catch (e) {
          throw new Error('接口返回格式异常，请确认 Webman 服务已启动，并通过 http://域名:端口/install 访问安装向导');
        }
      });
    }).catch(function (err) {
      throw new Error(err && err.message ? err.message : '接口请求失败，请检查访问地址和服务状态');
    });
  }

  function showToast(message) {
    toast.innerHTML = message;
    toastMask.classList.add('show');
    setTimeout(function () { toastMask.classList.remove('show'); }, 2600);
  }

  function checkSummary(checks) {
    if (!Array.isArray(checks) || checks.length === 0) return '';
    return '<div class="toast-checks">' + checks.map(function (item) {
      return '<div><strong>' + item.name + '</strong><span>' + item.message + '</span></div>';
    }).join('') + '</div>';
  }

  function setStep(index) {
    step = Math.max(0, Math.min(4, index));
    panels.forEach(function (panel) {
      panel.classList.toggle('active', Number(panel.dataset.panel) === step);
    });
    steps.forEach(function (item, idx) {
      item.classList.toggle('active', idx === step);
      item.classList.toggle('done', idx < step);
    });
    document.getElementById('stepTitle').textContent = titles[step][0];
    document.getElementById('stepDesc').textContent = titles[step][1];
    prevBtn.style.visibility = step === 0 ? 'hidden' : 'visible';
    nextBtn.textContent = step === 3 ? '开始安装' : (step === 4 ? '进入后台' : '下一步');
  }

  function formData() {
    var data = {};
    new FormData(form).forEach(function (value, key) {
      data[key] = String(value);
    });
    return data;
  }

  function renderEnv(items) {
    var list = document.getElementById('envList');
    list.innerHTML = '';
    items.forEach(function (item) {
      var row = document.createElement('div');
      row.className = 'check-row';
      row.innerHTML = '<strong></strong><small></small><span class="tag"></span>';
      row.querySelector('strong').textContent = item.name;
      row.querySelector('small').textContent = item.value || item.message;
      var tag = row.querySelector('.tag');
      tag.classList.add(item.status);
      tag.textContent = item.message;
      list.appendChild(row);
    });
  }

  function checkEnv() {
    return api('/adminapi/install/check-env').then(function (res) {
      if (res.code !== 200) throw new Error(res.msg || '环境检测失败');
      renderEnv(res.data.items || []);
      return !!res.data.passed;
    }).catch(function (err) {
      showToast(err.message);
      return false;
    });
  }

  function loadStatus() {
    api('/adminapi/install/status').then(function (res) {
      var pill = document.getElementById('installStatus');
      if (res.data && res.data.installed) {
        pill.textContent = '已安装';
        pill.classList.add('success');
        setStep(4);
      } else {
        pill.textContent = '未安装';
        if (res.data && res.data.suspicious_existing_data) {
          showToast('当前数据库疑似已有核心表，请确认不是生产数据后再继续。' + checkSummary((res.data.core_tables || []).map(function (table) {
            return { name: table, message: '已存在' };
          })));
        }
      }
    });
  }

  function loadSecrets() {
    api('/adminapi/install/secrets').then(function (res) {
      if (res.code !== 200 || !res.data) return;
      Object.keys(res.data).forEach(function (key) {
        var input = form.querySelector('[name="' + key + '"]');
        if (input) input.value = res.data[key];
      });
    });
  }

  function testEndpoint(url, label) {
    return api(url, {
      method: 'POST',
      body: JSON.stringify(formData())
    }).then(function (res) {
      showToast((res.msg || label) + checkSummary(res.data && res.data.checks));
      return res.code === 200;
    });
  }

  function runInstall() {
    validatePasswords(false);
    if (!form.reportValidity()) return;
    if (!validatePasswords(true)) return;
    installLog.textContent = '开始安装...\n';
    progressBar.style.width = '20%';
    nextBtn.disabled = true;
    prevBtn.disabled = true;
    api('/adminapi/install/run', {
      method: 'POST',
      body: JSON.stringify(formData())
    }).then(function (res) {
      if (res.code !== 200) {
        throw new Error(res.msg || '安装失败');
      }
      progressBar.style.width = '100%';
      installLog.textContent += '数据库迁移完成：' + (res.data.migrations.executed || []).length + ' 项\n';
      installLog.textContent += '基础数据初始化完成：' + (res.data.seeders || []).length + ' 项\n';
      installLog.textContent += '平台密钥已就绪\n安装锁已写入\n';
      installLog.textContent += '请重启 Webman 服务，让新的 .env 配置生效\n';
      setTimeout(function () { setStep(4); }, 500);
    }).catch(function (err) {
      progressBar.style.width = '100%';
      installLog.textContent += '\n' + err.message;
      showToast(err.message);
    }).finally(function () {
      nextBtn.disabled = false;
      prevBtn.disabled = false;
    });
  }

  nextBtn.addEventListener('click', function () {
    if (step === 0 && !document.getElementById('agree').checked) {
      showToast('请先确认协议与免责声明');
      return;
    }
    if (step === 1) {
      checkEnv().then(function (passed) {
        if (passed) setStep(2);
        else showToast('存在必须处理的环境问题');
      });
      return;
    }
    if (step === 2) {
      validatePasswords(false);
      if (!form.reportValidity()) return;
      if (!validatePasswords(true)) return;
    }
    if (step === 3) {
      runInstall();
      return;
    }
    if (step === 4) {
      location.href = '/admin';
      return;
    }
    setStep(step + 1);
    if (step === 1) checkEnv();
  });

  function validatePasswords(report) {
    var password = form.querySelector('[name="admin_password"]');
    var confirm = form.querySelector('[name="admin_password_confirm"]');
    if (!password || !confirm) return true;
    confirm.setCustomValidity('');
    if (!password.value || !confirm.value) return true;
    if (password.value !== confirm.value) {
      confirm.setCustomValidity('两次输入的管理员密码不一致');
      if (report) confirm.reportValidity();
      return false;
    }
    return true;
  }

  form.querySelector('[name="admin_password"]').addEventListener('input', function () {
    validatePasswords(false);
  });
  form.querySelector('[name="admin_password_confirm"]').addEventListener('input', function () {
    validatePasswords(false);
  });
  toastMask.addEventListener('click', function () {
    toastMask.classList.remove('show');
  });

  prevBtn.addEventListener('click', function () {
    setStep(step - 1);
  });

  document.getElementById('checkEnvBtn').addEventListener('click', checkEnv);
  document.getElementById('testDbBtn').addEventListener('click', function () {
    testEndpoint('/adminapi/install/test-db', '数据库连接成功');
  });
  document.getElementById('testRedisBtn').addEventListener('click', function () {
    testEndpoint('/adminapi/install/test-redis', 'Redis 连接成功');
  });

  loadStatus();
  initDefaults();
  loadSecrets();
  checkEnv();
  setStep(0);
})();
