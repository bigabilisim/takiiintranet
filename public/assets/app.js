(function () {
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-confirm-message]');

    if (!trigger || window.confirm(trigger.dataset.confirmMessage || '')) {
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);
})();

(function () {
  const drawer = document.querySelector('[data-mobile-drawer]');
  const toggles = Array.from(document.querySelectorAll('[data-mobile-menu-toggle]'));
  const closeButton = document.querySelector('[data-mobile-menu-close]');
  const overlay = document.querySelector('[data-mobile-menu-overlay]');
  const mainPanel = document.querySelector('.main-panel');
  const bottomNav = document.querySelector('.mobile-bottom-nav');

  if (!drawer || toggles.length === 0 || !overlay) {
    return;
  }

  const mobileQuery = window.matchMedia('(max-width: 980px)');
  const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  let lastTrigger = null;

  function isOpen() {
    return document.body.classList.contains('mobile-menu-open');
  }

  function setExpanded(expanded) {
    toggles.forEach((toggle) => toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false'));
  }

  function setInert(element, inert) {
    if (!element) {
      return;
    }

    element.toggleAttribute('inert', inert);
  }

  function setDrawerState(open, restoreFocus = true) {
    const shouldOpen = open && mobileQuery.matches;
    document.body.classList.toggle('mobile-menu-open', shouldOpen);
    setExpanded(shouldOpen);
    setInert(drawer, mobileQuery.matches && !shouldOpen);
    setInert(mainPanel, shouldOpen);
    setInert(bottomNav, shouldOpen);

    if (shouldOpen) {
      window.setTimeout(() => (closeButton || drawer).focus(), 30);
    } else if (restoreFocus && lastTrigger && document.contains(lastTrigger)) {
      lastTrigger.focus();
    }
  }

  toggles.forEach((toggle) => {
    toggle.addEventListener('click', () => {
      lastTrigger = toggle;
      setDrawerState(!isOpen());
    });
  });

  if (closeButton) {
    closeButton.addEventListener('click', () => setDrawerState(false));
  }

  overlay.addEventListener('click', () => setDrawerState(false));

  drawer.addEventListener('click', (event) => {
    if (event.target.closest('a[href]')) {
      setDrawerState(false, false);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (!isOpen()) {
      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      setDrawerState(false);
      return;
    }

    if (event.key !== 'Tab') {
      return;
    }

    const focusable = Array.from(drawer.querySelectorAll(focusableSelector)).filter((element) => element.offsetParent !== null);

    if (focusable.length === 0) {
      event.preventDefault();
      drawer.focus();
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  const syncResponsiveState = () => {
    if (!mobileQuery.matches) {
      document.body.classList.remove('mobile-menu-open');
      setExpanded(false);
      setInert(drawer, false);
      setInert(mainPanel, false);
      setInert(bottomNav, false);
      return;
    }

    setDrawerState(false, false);
  };

  if (typeof mobileQuery.addEventListener === 'function') {
    mobileQuery.addEventListener('change', syncResponsiveState);
  } else {
    mobileQuery.addListener(syncResponsiveState);
  }

  syncResponsiveState();
})();

(function () {
  const responsiveDetails = Array.from(document.querySelectorAll('details[data-mobile-collapsible]'));

  if (responsiveDetails.length === 0) {
    return;
  }

  const mobileQuery = window.matchMedia('(max-width: 640px)');

  function syncDetails() {
    responsiveDetails.forEach((details) => {
      details.open = mobileQuery.matches ? details.hasAttribute('data-mobile-default-open') : true;
    });
  }

  if (typeof mobileQuery.addEventListener === 'function') {
    mobileQuery.addEventListener('change', syncDetails);
  } else {
    mobileQuery.addListener(syncDetails);
  }

  syncDetails();
})();

(function () {
  const versionList = document.querySelector('[data-version-list]');

  if (!versionList) {
    return;
  }

  const cards = Array.from(versionList.querySelectorAll('[data-version-card]'));
  const loadMore = versionList.querySelector('[data-version-load-more]');
  const initialLimit = 10;

  if (!loadMore || cards.length <= initialLimit) {
    return;
  }

  const showLabel = versionList.dataset.showLabel || 'Show __count__ older versions';
  const hideLabel = versionList.dataset.hideLabel || 'Show fewer versions';
  const hiddenCount = cards.length - initialLimit;
  let expanded = false;

  function render() {
    cards.forEach((card, index) => {
      card.hidden = !expanded && index >= initialLimit;
    });
    loadMore.hidden = false;
    loadMore.textContent = expanded ? hideLabel : showLabel.replace('__count__', String(hiddenCount));
    loadMore.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  }

  loadMore.addEventListener('click', () => {
    expanded = !expanded;
    render();

    if (!expanded) {
      versionList.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'start'
      });
    }
  });

  render();
})();

(function () {
  const messagesNavItems = Array.from(document.querySelectorAll('[data-module-nav="messages"][data-badge-template]'));

  if (messagesNavItems.length === 0) {
    return;
  }

  const badgeTemplate = messagesNavItems[0].dataset.badgeTemplate || '__count__ unread';

  function badgeLabel(count) {
    return badgeTemplate.replace('__count__', String(count));
  }

  function updateBadge(count) {
    messagesNavItems.forEach((messagesNav) => {
      let badge = messagesNav.querySelector('[data-module-badge="messages"]');

      if (count < 1) {
        if (badge) {
          badge.remove();
        }

        return;
      }

      if (!badge) {
        badge = document.createElement('strong');
        badge.className = 'nav-badge';
        badge.dataset.moduleBadge = 'messages';
        messagesNav.appendChild(badge);
      }

      badge.textContent = String(count);
      badge.setAttribute('aria-label', badgeLabel(count));
    });
  }

  async function refreshMessageBadge() {
    try {
      const response = await fetch('/messages/unread-count', {
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json'
        }
      });

      if (!response.ok) {
        return;
      }

      const payload = await response.json();
      updateBadge(Number(payload.count || 0));
    } catch (error) {
      // The server-rendered badge remains available when polling is unavailable.
    }
  }

  refreshMessageBadge();

  window.setInterval(() => {
    if (!document.hidden) {
      refreshMessageBadge();
    }
  }, 30000);

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      refreshMessageBadge();
    }
  });
})();

(function () {
  const root = document.querySelector('[data-leave-self-service]');

  if (!root) {
    return;
  }

  const tabs = Array.from(root.querySelectorAll('[data-leave-self-service-tab]'));
  const panels = Array.from(root.querySelectorAll('[data-leave-self-service-panel]'));
  const searchInput = root.querySelector('[data-leave-self-service-search]');

  if (tabs.length === 0 || panels.length === 0) {
    return;
  }

  function normalize(value) {
    const mapped = String(value || '').replace(/[İIıŞşĞğÜüÖöÇç]/g, (character) => ({
      İ: 'i',
      I: 'i',
      ı: 'i',
      Ş: 's',
      ş: 's',
      Ğ: 'g',
      ğ: 'g',
      Ü: 'u',
      ü: 'u',
      Ö: 'o',
      ö: 'o',
      Ç: 'c',
      ç: 'c'
    }[character] || character));

    return mapped
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim();
  }

  function activatePanel(name, focusTab = false, updateHash = true) {
    const activeTab = tabs.find((tab) => tab.dataset.leaveSelfServiceTab === name) || tabs[0];
    const activeName = activeTab.dataset.leaveSelfServiceTab;

    tabs.forEach((tab) => {
      const isActive = tab === activeTab;
      tab.classList.toggle('is-active', isActive);
      tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
      tab.tabIndex = isActive ? 0 : -1;
    });

    panels.forEach((panel) => {
      const isActive = panel.dataset.leaveSelfServicePanel === activeName;
      panel.classList.toggle('is-active', isActive);
      panel.hidden = !isActive;
    });

    if (focusTab) {
      activeTab.focus();
    }

    const activePanel = panels.find((panel) => panel.dataset.leaveSelfServicePanel === activeName);

    if (updateHash && activePanel && window.history && window.history.replaceState) {
      window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#${activePanel.id}`);
    }
  }

  function applyFilter() {
    const query = normalize(searchInput ? searchInput.value : '');

    panels.forEach((panel) => {
      const records = Array.from(panel.querySelectorAll('[data-leave-self-service-record]'));
      let visibleCount = 0;

      records.forEach((record) => {
        const matches = query === '' || normalize(record.dataset.leaveSearchText).includes(query);
        record.hidden = !matches;

        if (matches) {
          visibleCount += 1;
        }
      });

      const filterEmpty = panel.querySelector('[data-leave-filter-empty]');

      if (filterEmpty) {
        filterEmpty.hidden = query === '' || records.length === 0 || visibleCount > 0;
      }
    });
  }

  root.classList.add('is-enhanced');

  const hashPanel = panels.find((panel) => `#${panel.id}` === window.location.hash);
  activatePanel(hashPanel ? hashPanel.dataset.leaveSelfServicePanel : 'requests', false, false);

  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activatePanel(tab.dataset.leaveSelfServiceTab));
    tab.addEventListener('keydown', (event) => {
      let nextIndex = index;

      if (event.key === 'ArrowRight') {
        nextIndex = (index + 1) % tabs.length;
      } else if (event.key === 'ArrowLeft') {
        nextIndex = (index - 1 + tabs.length) % tabs.length;
      } else if (event.key === 'Home') {
        nextIndex = 0;
      } else if (event.key === 'End') {
        nextIndex = tabs.length - 1;
      } else {
        return;
      }

      event.preventDefault();
      activatePanel(tabs[nextIndex].dataset.leaveSelfServiceTab, true);
    });
  });

  root.querySelectorAll('[data-leave-open-panel]').forEach((trigger) => {
    trigger.addEventListener('click', () => {
      activatePanel(trigger.dataset.leaveOpenPanel || 'requests', true);
      root.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'start'
      });
    });
  });

  window.addEventListener('hashchange', () => {
    const matchingPanel = panels.find((panel) => `#${panel.id}` === window.location.hash);

    if (matchingPanel) {
      activatePanel(matchingPanel.dataset.leaveSelfServicePanel, false, false);
    }
  });

  if (searchInput) {
    searchInput.addEventListener('input', applyFilter);
  }

  applyFilter();
})();

(function () {
  const forms = document.querySelectorAll('[data-leave-request-form], [data-leave-edit-form]');

  if (forms.length === 0) {
    return;
  }

  forms.forEach((form) => {
    const dayPart = form.querySelector('[data-leave-day-part]');
    const startsOn = form.querySelector('[data-leave-starts-on]');
    const endsOn = form.querySelector('[data-leave-ends-on]');

    if (!dayPart || !startsOn || !endsOn) {
      return;
    }

    function syncHalfDayDates() {
      if (dayPart.value !== 'morning' && dayPart.value !== 'afternoon') {
        return;
      }

      endsOn.value = startsOn.value;
    }

    dayPart.addEventListener('change', syncHalfDayDates);
    startsOn.addEventListener('change', syncHalfDayDates);
    syncHalfDayDates();
  });
})();

(function () {
  const containers = Array.from(document.querySelectorAll('[data-leave-entitlement-rules]'));

  if (containers.length === 0) {
    return;
  }

  function setOpen(container, open) {
    const trigger = container.querySelector('[data-leave-entitlement-rules-trigger]');
    container.classList.toggle('is-open', open);

    if (trigger) {
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
  }

  function closeAll(except = null) {
    containers.forEach((container) => {
      if (container !== except) {
        setOpen(container, false);
      }
    });
  }

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-leave-entitlement-rules-trigger]');

    if (!trigger) {
      closeAll();
      return;
    }

    const container = trigger.closest('[data-leave-entitlement-rules]');

    if (!container) {
      return;
    }

    const shouldOpen = !container.classList.contains('is-open');
    closeAll(container);
    setOpen(container, shouldOpen);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeAll();
    }
  });
})();

(function () {
  const assistantLocationRoles = Array.from(document.querySelectorAll('[data-hr-assistant-location-role]'));

  assistantLocationRoles.forEach((roleInput) => {
    roleInput.addEventListener('change', () => {
      if (!roleInput.checked) {
        return;
      }

      const form = roleInput.closest('form');

      if (!form) {
        return;
      }

      form.querySelectorAll('[data-hr-assistant-location-role]').forEach((otherRole) => {
        if (otherRole !== roleInput) {
          otherRole.checked = false;
        }
      });
    });
  });
})();

(function () {
  const input = document.querySelector('[data-personnel-filter]');
  const rows = Array.from(document.querySelectorAll('[data-personnel-row]'));
  const emptyState = document.querySelector('[data-personnel-empty]');
  const groupButtons = Array.from(document.querySelectorAll('[data-personnel-group-filter]'));
  const groupHeaders = Array.from(document.querySelectorAll('[data-personnel-group-header]'));
  const hideTimers = new WeakMap();
  let activeGroup = 'all';

  if (!input || rows.length === 0) {
    return;
  }

  function normalize(value) {
    const mapped = String(value || '').replace(/[İIıŞşĞğÜüÖöÇç]/g, (character) => ({
      İ: 'i',
      I: 'i',
      ı: 'i',
      Ş: 's',
      ş: 's',
      Ğ: 'g',
      ğ: 'g',
      Ü: 'u',
      ü: 'u',
      Ö: 'o',
      ö: 'o',
      Ç: 'c',
      ç: 'c'
    }[character] || character));

    return mapped
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim();
  }

  function showRow(row) {
    const timer = hideTimers.get(row);

    if (timer) {
      window.clearTimeout(timer);
      hideTimers.delete(row);
    }

    row.hidden = false;
    window.requestAnimationFrame(() => {
      if (row.dataset.personnelVisible === '1') {
        row.classList.remove('is-filter-hidden');
      }
    });
  }

  function hideRow(row) {
    if (row.hidden) {
      return;
    }

    row.classList.add('is-filter-hidden');

    const timer = window.setTimeout(() => {
      if (row.classList.contains('is-filter-hidden')) {
        row.hidden = true;
      }
    }, 230);

    hideTimers.set(row, timer);
  }

  function applyFilter() {
    const query = normalize(input.value);
    let visibleCount = 0;

    rows.forEach((row) => {
      const searchText = normalize(row.dataset.personnelSearch);
      const group = row.dataset.personnelGroup || 'office';
      const matchesGroup = activeGroup === 'all' || group === activeGroup;
      const matches = matchesGroup && (query === '' || searchText.includes(query));
      row.dataset.personnelVisible = matches ? '1' : '0';

      if (matches) {
        visibleCount++;
        showRow(row);
      } else {
        hideRow(row);
      }
    });

    if (emptyState) {
      emptyState.hidden = visibleCount > 0;
    }

    groupHeaders.forEach((header) => {
      const group = header.dataset.personnelGroup || '';
      const hasVisibleRow = rows.some((row) => (row.dataset.personnelGroup || 'office') === group && row.dataset.personnelVisible === '1');
      header.hidden = !hasVisibleRow;
    });
  }

  input.addEventListener('input', applyFilter);

  groupButtons.forEach((button) => {
    button.addEventListener('click', () => {
      activeGroup = button.dataset.personnelGroupFilter || 'all';

      groupButtons.forEach((candidate) => {
        const isActive = candidate === button;
        candidate.classList.toggle('is-active', isActive);
        candidate.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });

      applyFilter();
    });
  });

  applyFilter();
})();

(function () {
  const patternDays = {
    weekdays: ['mon', 'tue', 'wed', 'thu', 'fri'],
    weekend: ['sat', 'sun'],
    all: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
  };
  const forms = document.querySelectorAll('[data-shift-template-form]');

  forms.forEach((form) => {
    const pattern = form.querySelector('[data-shift-day-pattern]');
    const dayInputs = Array.from(form.querySelectorAll('[data-shift-day-checkbox]'));

    if (!pattern || dayInputs.length === 0) {
      return;
    }

    function syncDays() {
      const selectedDays = patternDays[pattern.value];

      if (!selectedDays) {
        return;
      }

      dayInputs.forEach((input) => {
        input.checked = selectedDays.includes(input.value);
      });
    }

    pattern.addEventListener('change', syncDays);
    syncDays();
  });

  const dateCalendar = document.querySelector('[data-shift-date-calendar]');

  if (dateCalendar) {
    const dateForm = dateCalendar.closest('form');
    const monthInput = dateForm ? dateForm.querySelector('input[name="month"]') : null;
    const datePatternButtons = dateForm
      ? Array.from(dateForm.querySelectorAll('[data-shift-date-pattern]'))
      : [];
    const locale = dateCalendar.dataset.locale || document.documentElement.lang || 'tr-TR';
    const weekdayFormatter = new Intl.DateTimeFormat(locale, { weekday: 'short' });

    function dateValue(year, monthIndex, day) {
      return `${year}-${String(monthIndex + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    }

    function renderDateCalendar() {
      if (!monthInput || !/^\d{4}-\d{2}$/.test(monthInput.value)) {
        return;
      }

      const [year, month] = monthInput.value.split('-').map(Number);
      const monthIndex = month - 1;
      const dayCount = new Date(year, month, 0, 12).getDate();
      dateCalendar.replaceChildren();

      for (let day = 1; day <= dayCount; day += 1) {
        const date = new Date(year, monthIndex, day, 12);
        const weekday = date.getDay();
        const label = document.createElement('label');
        const input = document.createElement('input');
        const text = document.createElement('span');
        const number = document.createElement('strong');
        const weekdayText = document.createElement('small');

        if (day === 1) {
          label.style.gridColumnStart = String(weekday === 0 ? 7 : weekday);
        }

        input.type = 'checkbox';
        input.name = 'working_dates[]';
        input.value = dateValue(year, monthIndex, day);
        input.checked = weekday >= 1 && weekday <= 5;
        number.textContent = String(day).padStart(2, '0');
        weekdayText.textContent = weekdayFormatter.format(date);
        text.append(number, weekdayText);
        label.append(input, text);
        dateCalendar.append(label);
      }
    }

    function applyDatePattern(pattern) {
      const inputs = Array.from(dateCalendar.querySelectorAll('input[name="working_dates[]"]'));

      inputs.forEach((input) => {
        const date = new Date(`${input.value}T12:00:00`);
        const weekday = date.getDay();
        input.checked = pattern === 'all' || (pattern === 'weekdays' && weekday >= 1 && weekday <= 5);
      });
    }

    if (monthInput) {
      monthInput.addEventListener('change', renderDateCalendar);
    }

    datePatternButtons.forEach((button) => {
      button.addEventListener('click', () => applyDatePattern(button.dataset.shiftDatePattern || 'clear'));
    });
  }

  const selectAll = document.querySelector('[data-shift-select-all]');
  const personInputs = Array.from(document.querySelectorAll('[data-shift-person-checkbox]'));

  if (!selectAll || personInputs.length === 0) {
    return;
  }

  selectAll.addEventListener('change', () => {
    personInputs.forEach((input) => {
      if (!input.disabled) {
        input.checked = selectAll.checked;
      }
    });
  });

  personInputs.forEach((input) => {
    input.addEventListener('change', () => {
      if (!input.checked) {
        selectAll.checked = false;
      }
    });
  });
})();

(function () {
  const permissionCards = document.querySelectorAll('[data-permission-card]');

  if (permissionCards.length === 0) {
    return;
  }

  permissionCards.forEach((card) => {
    const inputs = Array.from(card.querySelectorAll('[data-permission-input]'));
    const inputsByPermission = new Map(inputs.map((input) => [input.value, input]));

    function uncheckChildren(parentPermission) {
      inputs.forEach((input) => {
        if (input.dataset.parentPermission === parentPermission && !input.disabled) {
          input.checked = false;
        }
      });
    }

    function checkParent(parentPermission) {
      const parentInput = inputsByPermission.get(parentPermission);

      if (parentInput && !parentInput.checked) {
        parentInput.checked = true;
      }
    }

    inputs.forEach((input) => {
      input.addEventListener('change', () => {
        if (input.checked && input.dataset.parentPermission) {
          checkParent(input.dataset.parentPermission);
        }

        if (input.dataset.isModulePermission === '1' && !input.checked) {
          uncheckChildren(input.value);
        }
      });
    });
  });
})();

(function () {
  const triggerSelector = '[data-calendar-popover-trigger]';
  let popover = null;
  let activeTrigger = null;

  function createElement(tagName, className, text) {
    const element = document.createElement(tagName);

    if (className) {
      element.className = className;
    }

    if (typeof text === 'string') {
      element.textContent = text;
    }

    return element;
  }

  function appendField(list, label, value) {
    if (!value) {
      return;
    }

    const wrapper = createElement('div', 'calendar-popover-field');
    wrapper.appendChild(createElement('dt', '', label));
    wrapper.appendChild(createElement('dd', '', value));
    list.appendChild(wrapper);
  }

  function closePopover() {
    if (popover) {
      popover.remove();
    }

    popover = null;
    activeTrigger = null;
  }

  function positionPopover(trigger) {
    if (!popover) {
      return;
    }

    const gap = 12;
    const margin = 12;
    const rect = trigger.getBoundingClientRect();
    const width = popover.offsetWidth;
    const height = popover.offsetHeight;
    let left = rect.right + gap;
    let top = rect.top;

    if (left + width > window.innerWidth - margin) {
      left = rect.left - width - gap;
    }

    if (left < margin) {
      left = Math.min(Math.max(margin, rect.left), window.innerWidth - width - margin);
    }

    if (top + height > window.innerHeight - margin) {
      top = window.innerHeight - height - margin;
    }

    if (top < margin) {
      top = margin;
    }

    popover.style.left = `${left}px`;
    popover.style.top = `${top}px`;
  }

  function openPopover(trigger) {
    closePopover();
    activeTrigger = trigger;

    popover = createElement('aside', 'calendar-popover');
    popover.setAttribute('role', 'dialog');
    popover.setAttribute('aria-label', trigger.dataset.popoverTitle || '');

    const header = createElement('header', 'calendar-popover-header');
    header.appendChild(createElement('h3', '', trigger.dataset.popoverTitle || ''));

    const closeButton = createElement('button', 'calendar-popover-close', '×');
    closeButton.type = 'button';
    closeButton.setAttribute('aria-label', trigger.dataset.closeLabel || 'Close');
    closeButton.addEventListener('click', closePopover);
    header.appendChild(closeButton);
    popover.appendChild(header);

    const fields = createElement('dl', 'calendar-popover-fields');
    appendField(fields, trigger.dataset.labelRequestId, trigger.dataset.requestId);
    appendField(fields, trigger.dataset.labelRequester, trigger.dataset.requester);
    appendField(fields, trigger.dataset.labelDepartment, trigger.dataset.department);
    appendField(fields, trigger.dataset.labelType, trigger.dataset.type);
    appendField(fields, trigger.dataset.labelDateRange, trigger.dataset.dateRange);
    appendField(fields, trigger.dataset.labelDayPart, trigger.dataset.dayPart);
    appendField(fields, trigger.dataset.labelTotalDays, trigger.dataset.totalDays);
    appendField(fields, trigger.dataset.labelStatus, trigger.dataset.status);
    popover.appendChild(fields);

    if (trigger.dataset.canAct === '1' && trigger.dataset.decisionUrl) {
      const actionSection = createElement('section', 'calendar-popover-actions');
      actionSection.appendChild(createElement('h4', '', trigger.dataset.labelDecision || ''));

      const approveForm = createElement('form', 'calendar-popover-action-form');
      approveForm.method = 'post';
      approveForm.action = trigger.dataset.decisionUrl;
      const approveToken = createElement('input', '');
      approveToken.type = 'hidden';
      approveToken.name = '_token';
      approveToken.value = trigger.dataset.csrfToken || '';
      const approveDecision = createElement('input', '');
      approveDecision.type = 'hidden';
      approveDecision.name = 'decision';
      approveDecision.value = 'approve';
      const approveButton = createElement('button', 'button compact approve', trigger.dataset.labelApprove || '');
      approveButton.type = 'submit';
      approveForm.appendChild(approveToken);
      approveForm.appendChild(approveDecision);
      approveForm.appendChild(approveButton);

      const rejectForm = createElement('form', 'calendar-popover-action-form');
      rejectForm.method = 'post';
      rejectForm.action = trigger.dataset.decisionUrl;
      const rejectToken = createElement('input', '');
      rejectToken.type = 'hidden';
      rejectToken.name = '_token';
      rejectToken.value = trigger.dataset.csrfToken || '';
      const rejectDecision = createElement('input', '');
      rejectDecision.type = 'hidden';
      rejectDecision.name = 'decision';
      rejectDecision.value = 'reject';
      const rejectLabel = createElement('label', '');
      rejectLabel.appendChild(createElement('span', '', trigger.dataset.labelRejectReason || ''));
      const rejectTextarea = createElement('textarea', '');
      rejectTextarea.name = 'decision_note';
      rejectTextarea.rows = 2;
      rejectTextarea.maxLength = 500;
      rejectTextarea.required = true;
      rejectTextarea.placeholder = trigger.dataset.labelRejectPlaceholder || '';
      rejectLabel.appendChild(rejectTextarea);
      const rejectButton = createElement('button', 'button compact reject', trigger.dataset.labelReject || '');
      rejectButton.type = 'submit';
      rejectForm.appendChild(rejectToken);
      rejectForm.appendChild(rejectDecision);
      rejectForm.appendChild(rejectLabel);
      rejectForm.appendChild(rejectButton);

      actionSection.appendChild(approveForm);
      actionSection.appendChild(rejectForm);
      popover.appendChild(actionSection);
    }

    document.body.appendChild(popover);
    positionPopover(trigger);
    window.requestAnimationFrame(() => popover && popover.classList.add('is-open'));
  }

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest(triggerSelector);

    if (trigger) {
      event.preventDefault();
      event.stopPropagation();

      if (activeTrigger === trigger) {
        closePopover();
        return;
      }

      openPopover(trigger);
      return;
    }

    if (popover && !popover.contains(event.target)) {
      closePopover();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closePopover();
    }
  });

  window.addEventListener('resize', () => {
    if (activeTrigger) {
      positionPopover(activeTrigger);
    }
  });

  window.addEventListener('scroll', (event) => {
    if (popover && event.target !== popover && !popover.contains(event.target)) {
      closePopover();
    }
  }, true);
})();
