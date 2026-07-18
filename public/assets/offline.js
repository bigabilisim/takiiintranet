(() => {
  const copy = {
    'tr-TR': {
      title: 'Çevrim dışı',
      body: 'Bağlantı yeniden kurulduğunda paneli kullanmaya devam edebilirsiniz.'
    },
    'en-US': {
      title: 'Offline',
      body: 'You can continue using the dashboard when the connection is restored.'
    },
    'de-DE': {
      title: 'Offline',
      body: 'Sobald die Verbindung wiederhergestellt ist, können Sie das Dashboard weiter nutzen.'
    },
    'ja-JP': {
      title: 'オフライン',
      body: '接続が復旧すると、ダッシュボードを引き続き利用できます。'
    }
  };
  const localeByLanguage = {
    tr: 'tr-TR',
    en: 'en-US',
    de: 'de-DE',
    ja: 'ja-JP'
  };
  const browserLanguages = Array.isArray(navigator.languages) && navigator.languages.length > 0
    ? navigator.languages
    : [navigator.language || 'tr-TR'];
  const locale = browserLanguages
    .map((language) => localeByLanguage[String(language).slice(0, 2).toLowerCase()])
    .find(Boolean) || 'tr-TR';
  const content = copy[locale];

  document.documentElement.lang = locale;
  document.title = `MyTakii Intranet | ${content.title}`;
  document.querySelector('[data-offline-title]').textContent = content.title;
  document.querySelector('[data-offline-body]').textContent = content.body;
})();
