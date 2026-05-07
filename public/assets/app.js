(function () {
  const messagesNav = document.querySelector('[data-module-nav="messages"][data-badge-template]');

  if (!messagesNav) {
    return;
  }

  const badgeTemplate = messagesNav.dataset.badgeTemplate || '__count__ unread';

  function badgeLabel(count) {
    return badgeTemplate.replace('__count__', String(count));
  }

  function updateBadge(count) {
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
  const input = document.querySelector('[data-personnel-filter]');
  const rows = Array.from(document.querySelectorAll('[data-personnel-row]'));
  const emptyState = document.querySelector('[data-personnel-empty]');
  const hideTimers = new WeakMap();

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
      row.classList.remove('is-filter-hidden');
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
      const matches = query === '' || searchText.includes(query);

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
  }

  input.addEventListener('input', applyFilter);
  applyFilter();
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

  function readApprovals(trigger) {
    try {
      const approvals = JSON.parse(trigger.dataset.approvals || '[]');
      return Array.isArray(approvals) ? approvals : [];
    } catch (error) {
      return [];
    }
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
    appendField(fields, trigger.dataset.labelTotalDays, trigger.dataset.totalDays);
    appendField(fields, trigger.dataset.labelStatus, trigger.dataset.status);
    popover.appendChild(fields);

    if ((trigger.dataset.note || '').trim() !== '') {
      const note = createElement('section', 'calendar-popover-note');
      note.appendChild(createElement('h4', '', trigger.dataset.labelNote || ''));
      note.appendChild(createElement('p', '', trigger.dataset.note || ''));
      popover.appendChild(note);
    }

    const approvals = readApprovals(trigger);

    if (approvals.length > 0) {
      const flow = createElement('section', 'calendar-popover-flow');
      flow.appendChild(createElement('h4', '', trigger.dataset.labelApprovalFlow || ''));
      const list = createElement('ol', '');

      approvals.forEach((approval) => {
        const item = createElement('li', '');
        const line = createElement('div', '');
        line.appendChild(createElement('strong', '', approval.label || ''));
        line.appendChild(createElement('span', '', approval.status || ''));
        item.appendChild(line);

        const meta = [approval.actor || approval.assignee || '', approval.source || '', approval.acted_at || '']
          .filter(Boolean)
          .join(' / ');

        if (meta) {
          item.appendChild(createElement('small', '', meta));
        }

        list.appendChild(item);
      });

      flow.appendChild(list);
      popover.appendChild(flow);
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
