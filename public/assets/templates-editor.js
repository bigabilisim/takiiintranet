(function () {
  const builder = document.querySelector('[data-template-builder]');
  const form = document.querySelector('[data-template-form]');

  if (!builder || !form) {
    return;
  }

  const htmlField = form.querySelector('[data-template-html]');
  const cssField = form.querySelector('[data-template-css]');
  const projectField = form.querySelector('[data-template-project]');
  const canEdit = builder.dataset.canEdit === '1';
  const testForms = document.querySelectorAll('[data-template-test-form]');
  const i18n = window.KANSO_TEMPLATE_I18N || {};

  const text = (key, fallback) => (typeof i18n[key] === 'string' && i18n[key] !== '' ? i18n[key] : fallback);
  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[character]));

  if (!window.grapesjs) {
    builder.textContent = builder.dataset.emptyText || 'Editor could not be loaded.';
    builder.classList.add('is-unavailable');
    return;
  }

  const editor = window.grapesjs.init({
    container: '#template-builder',
    height: '720px',
    width: 'auto',
    storageManager: false,
    fromElement: false,
    components: htmlField ? htmlField.value : '',
    style: cssField ? cssField.value : '',
    deviceManager: {
      devices: [
        { name: 'Desktop', width: '' },
        { name: 'Tablet', width: '768px', widthMedia: '980px' },
        { name: 'Mobile', width: '360px', widthMedia: '640px' }
      ]
    },
    blockManager: {
      blocks: [
        {
          id: 'section',
          label: text('block.section', 'Section'),
          category: text('category.layout', 'Layout'),
          content: `<section style="padding:24px"><h2>${escapeHtml(text('content.title', 'Title'))}</h2><p>${escapeHtml(text('content.body', 'Body text'))}</p></section>`
        },
        {
          id: 'two-columns',
          label: text('block.columns', '2 columns'),
          category: text('category.layout', 'Layout'),
          content: `<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div style="padding:16px;background:#f7f8f5">${escapeHtml(text('content.left', 'Left'))}</div><div style="padding:16px;background:#f7f8f5">${escapeHtml(text('content.right', 'Right'))}</div></div>`
        },
        {
          id: 'text',
          label: text('block.text', 'Text'),
          category: text('category.basic', 'Basic'),
          content: `<div data-gjs-type="text">${escapeHtml(text('content.text', 'Write your text here'))}</div>`
        },
        {
          id: 'button',
          label: text('block.button', 'Button'),
          category: text('category.mail', 'Mail'),
          content: `<a href="{{approval_link}}" style="display:inline-block;padding:12px 18px;border-radius:8px;background:#1f2428;color:#fff;text-decoration:none;font-weight:800">${escapeHtml(text('content.action', 'Action'))}</a>`
        },
        {
          id: 'metric',
          label: text('block.metric', 'Metric'),
          category: text('category.reports', 'Reports'),
          content: `<div style="padding:16px;border:1px solid #d7ddd8;border-radius:8px;background:#fff"><span style="color:#6c7076;font-size:12px">${escapeHtml(text('content.metric', 'Metric'))}</span><strong style="display:block;font-size:28px">42</strong></div>`
        },
        {
          id: 'table',
          label: text('block.table', 'Table'),
          category: text('category.reports', 'Reports'),
          content: `<table style="width:100%;border-collapse:collapse"><thead><tr><th style="padding:10px;border-bottom:1px solid #d7ddd8;text-align:left">${escapeHtml(text('content.title', 'Title'))}</th><th style="padding:10px;border-bottom:1px solid #d7ddd8;text-align:left">${escapeHtml(text('content.value', 'Value'))}</th></tr></thead><tbody><tr><td style="padding:10px;border-bottom:1px solid #d7ddd8">${escapeHtml(text('content.row', 'Row'))}</td><td style="padding:10px;border-bottom:1px solid #d7ddd8">0</td></tr></tbody></table>`
        },
        {
          id: 'image',
          label: text('block.image', 'Image'),
          category: text('category.basic', 'Basic'),
          content: { type: 'image' },
          activate: true
        }
      ]
    }
  });

  try {
    const projectData = projectField && projectField.value ? JSON.parse(projectField.value) : null;

    if (projectData) {
      editor.loadProjectData(projectData);
    }
  } catch (error) {
    builder.dataset.projectError = '1';
  }

  if (!canEdit) {
    editor.on('component:selected', (component) => component && component.set({ editable: false, draggable: false, removable: false, copyable: false }));
  }

  const syncForm = (targetForm) => {
    const htmlTargets = targetForm.querySelectorAll('[data-template-html], [data-template-test-html]');
    const cssTargets = targetForm.querySelectorAll('[data-template-css], [data-template-test-css]');
    const projectTargets = targetForm.querySelectorAll('[data-template-project], [data-template-test-project]');
    const nameTarget = targetForm.querySelector('[data-template-test-name]');
    const typeTarget = targetForm.querySelector('[data-template-test-type]');
    const nameSource = form.querySelector('[name="name"]');
    const typeSource = form.querySelector('[name="type"]');

    htmlTargets.forEach((target) => {
      target.value = editor.getHtml();
    });

    cssTargets.forEach((target) => {
      target.value = editor.getCss();
    });

    projectTargets.forEach((target) => {
      target.value = JSON.stringify(editor.getProjectData());
    });

    if (nameTarget && nameSource) {
      nameTarget.value = nameSource.value;
    }

    if (typeTarget && typeSource) {
      typeTarget.value = typeSource.value;
    }
  };

  form.addEventListener('submit', () => {
    syncForm(form);
  });

  testForms.forEach((testForm) => {
    testForm.addEventListener('submit', () => {
      syncForm(testForm);
    });
  });

  const reportTools = document.querySelector('[data-template-report-tools]');
  const reportPreviewButton = reportTools ? reportTools.querySelector('[data-template-report-preview]') : null;
  const reportPdfButton = reportTools ? reportTools.querySelector('[data-template-report-pdf]') : null;
  const reportDataField = reportTools ? reportTools.querySelector('[data-template-report-data]') : null;
  const reportStatus = reportTools ? reportTools.querySelector('[data-template-report-status]') : null;
  const reportPreviewSurface = reportTools ? reportTools.querySelector('[data-template-report-preview-surface]') : null;
  const templateTypeField = form.querySelector('[name="type"]');
  const templateNameField = form.querySelector('[name="name"]');
  let reportRenderTimer = null;

  function setReportStatus(message) {
    if (reportStatus) {
      reportStatus.textContent = message || '';
    }
  }

  function parseReportData() {
    if (!reportDataField || reportDataField.value.trim() === '') {
      return {};
    }

    return JSON.parse(reportDataField.value);
  }

  function replacePlaceholders(source, data) {
    return String(source || '').replace(/{{\s*([a-zA-Z0-9_.-]+)\s*}}/g, (match, key) => {
      if (!Object.prototype.hasOwnProperty.call(data, key)) {
        return match;
      }

      return escapeHtml(data[key]);
    });
  }

  function currentReportHtml(data) {
    const html = replacePlaceholders(editor.getHtml(), data);
    const css = editor.getCss();

    return `<style>${css}</style>${html}`;
  }

  function renderReportPreview() {
    if (!reportPreviewSurface || !reportTools || reportTools.hidden) {
      return false;
    }

    try {
      const data = parseReportData();
      reportPreviewSurface.innerHTML = currentReportHtml(data);
      setReportStatus(text('report.export_ready', 'Report preview is ready.'));
      return true;
    } catch (error) {
      setReportStatus(text('report.export_invalid_json', 'Check the sample data JSON format.'));
      return false;
    }
  }

  function scheduleReportPreview() {
    if (!reportTools || reportTools.hidden) {
      return;
    }

    window.clearTimeout(reportRenderTimer);
    reportRenderTimer = window.setTimeout(renderReportPreview, 350);
  }

  function reportFileName() {
    const source = templateNameField && templateNameField.value ? templateNameField.value : 'report';
    const slug = source
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/gi, '-')
      .replace(/^-+|-+$/g, '')
      .toLowerCase();

    return `${slug || 'report'}.pdf`;
  }

  function toggleReportTools() {
    if (!reportTools || !templateTypeField) {
      return;
    }

    reportTools.hidden = templateTypeField.value !== 'report';

    if (!reportTools.hidden) {
      scheduleReportPreview();
    }
  }

  async function exportReportPdf() {
    if (!reportPreviewSurface || !reportPdfButton) {
      return;
    }

    const jsPdfNamespace = window.jspdf || {};
    const JsPdf = jsPdfNamespace.jsPDF;

    if (!window.html2canvas || !JsPdf) {
      setReportStatus(text('report.export_unavailable', 'The PDF engine could not be loaded.'));
      return;
    }

    if (!renderReportPreview()) {
      return;
    }

    reportPdfButton.disabled = true;
    setReportStatus(text('report.export_working', 'Preparing PDF...'));

    try {
      const canvas = await window.html2canvas(reportPreviewSurface, {
        backgroundColor: '#ffffff',
        scale: Math.min(2, window.devicePixelRatio || 1.5),
        useCORS: true
      });
      const imageData = canvas.toDataURL('image/png');
      const pdf = new JsPdf({ orientation: 'portrait', unit: 'mm', format: 'a4' });
      const margin = 8;
      const pageWidth = pdf.internal.pageSize.getWidth();
      const pageHeight = pdf.internal.pageSize.getHeight();
      const contentWidth = pageWidth - margin * 2;
      const contentHeight = pageHeight - margin * 2;
      const imageHeight = canvas.height * contentWidth / canvas.width;
      let pageIndex = 0;

      pdf.addImage(imageData, 'PNG', margin, margin, contentWidth, imageHeight);

      while ((pageIndex + 1) * contentHeight < imageHeight) {
        pageIndex += 1;
        pdf.addPage();
        pdf.addImage(imageData, 'PNG', margin, margin - pageIndex * contentHeight, contentWidth, imageHeight);
      }

      pdf.save(reportFileName());
      setReportStatus(text('report.export_ready', 'Report preview is ready.'));
    } catch (error) {
      setReportStatus(text('report.export_unavailable', 'The PDF engine could not be loaded.'));
    } finally {
      reportPdfButton.disabled = false;
    }
  }

  if (reportPreviewButton) {
    reportPreviewButton.addEventListener('click', renderReportPreview);
  }

  if (reportPdfButton) {
    reportPdfButton.addEventListener('click', exportReportPdf);
  }

  if (reportDataField) {
    reportDataField.addEventListener('input', scheduleReportPreview);
  }

  if (templateTypeField) {
    templateTypeField.addEventListener('change', toggleReportTools);
  }

  editor.on('update', scheduleReportPreview);
  toggleReportTools();
}());
