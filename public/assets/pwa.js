(function () {
  const statusEl = document.querySelector('[data-pwa-status]');
  const enableButton = document.querySelector('[data-pwa-enable]');
  const testButton = document.querySelector('[data-pwa-test]');
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

  if (!statusEl || !enableButton || !('serviceWorker' in navigator)) {
    return;
  }

  let registration = null;

  function setStatus(message) {
    statusEl.textContent = message;
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; i++) {
      outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
  }

  async function postJson(url, payload) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
      },
      body: JSON.stringify(payload || {})
    });

    const result = await response.json().catch(() => ({}));

    if (!response.ok) {
      throw new Error(result.message || enableButton.dataset.errorText);
    }

    return result;
  }

  async function currentSubscription() {
    registration = registration || await navigator.serviceWorker.ready;
    return registration.pushManager.getSubscription();
  }

  async function syncSubscription(subscription) {
    if (!subscription) {
      return null;
    }

    await postJson('/push/subscribe', subscription.toJSON());
    return subscription;
  }

  async function refreshState() {
    const subscription = await currentSubscription();

    if (subscription) {
      enableButton.textContent = enableButton.dataset.enabledLabel;
      testButton.hidden = false;
      setStatus(enableButton.dataset.readyText);
    } else {
      enableButton.textContent = enableButton.dataset.disabledLabel;
      testButton.hidden = true;
      setStatus(enableButton.dataset.idleText);
    }
  }

  async function enablePush() {
    if (!('Notification' in window) || !('PushManager' in window)) {
      setStatus(enableButton.dataset.unsupportedText);
      return;
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
      setStatus(enableButton.dataset.deniedText);
      return;
    }

    registration = registration || await navigator.serviceWorker.ready;
    const config = await fetch('/push/config').then((response) => response.json());

    if (!config.ok || !config.publicKey) {
      throw new Error(enableButton.dataset.errorText);
    }
    let subscription = await registration.pushManager.getSubscription();

    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(config.publicKey)
      });
    }

    await syncSubscription(subscription);
    await refreshState();
  }

  async function boot() {
    registration = await navigator.serviceWorker.register('/service-worker.js', {
      scope: '/',
      updateViaCache: 'none'
    });
    await registration.update().catch(() => {});
    const subscription = await currentSubscription();

    if (subscription) {
      await syncSubscription(subscription).catch(() => {});
    }

    await refreshState();

    enableButton.addEventListener('click', enablePush);
    testButton.addEventListener('click', async () => {
      try {
        setStatus(testButton.dataset.sendingText);
        const subscription = await currentSubscription();

        if (subscription) {
          await syncSubscription(subscription);
        }

        const result = await postJson('/push/test', {});
        setStatus(result.message || testButton.dataset.sentText);
      } catch (error) {
        setStatus(error.message || enableButton.dataset.errorText);
      }
    });
  }

  boot().catch(() => {
    setStatus(enableButton.dataset.errorText);
  });
})();
