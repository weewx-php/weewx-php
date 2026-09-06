"use strict";
// Native forms remain the authoritative submission path.
const dirty = new Set();
document.documentElement.classList.add("js");
document.querySelectorAll(".station-table, .archive-table").forEach(table => {
  const headings = [...table.querySelectorAll("thead th")].map(cell => cell.textContent);
  table.querySelectorAll("tbody tr").forEach(row => {
    [...row.cells].forEach((cell, index) => { cell.dataset.label = headings[index] || ""; });
  });
});
const feedback = document.getElementById("action-feedback");
const labels = feedback ? JSON.parse(feedback.dataset.labels) : {};
let feedbackTimer, slowTimer;
const pendingForms = new Map();
function announce(message, kind = "info", persistent = false) {
  if (!feedback) return;
  clearTimeout(feedbackTimer);
  feedback.textContent = message;
  feedback.dataset.kind = kind;
  feedback.classList.add("is-visible");
  if (!persistent) feedbackTimer = setTimeout(() => feedback.classList.remove("is-visible"), 6000);
}
function updateDirty(form) {
  const status = form.querySelector("[data-form-status]");
  if (status) status.textContent = dirty.has(form) ? document.body.dataset.unsaved : labels.unchanged;
  form.classList.toggle("is-dirty", dirty.has(form));
}
function beginNavigation(message) {
  document.body.classList.add("is-navigating");
  announce(message, "working", true);
  clearTimeout(slowTimer);
  slowTimer = setTimeout(() => announce(labels.slow, "working", true), 15000);
}
function restoreInteraction() {
  clearTimeout(slowTimer);
  document.body.classList.remove("is-navigating");
  pendingForms.forEach((button, form) => {
    form.removeAttribute("aria-busy");
    if (button) {
      button.removeAttribute("aria-disabled");
      button.classList.remove("is-working");
    }
  });
  pendingForms.clear();
  if (feedback) feedback.classList.remove("is-visible");
}
function syncColumns() {
  document.querySelectorAll("select[data-column-target]").forEach(select => {
    const row = select.closest("tr").nextElementSibling;
    if (!row || !row.classList.contains("column-draft")) return;
    const open = select.value === "__new__";
    row.classList.toggle("is-open", open);
    row.querySelectorAll("input,select").forEach(input => { input.disabled = !open; });
  });
}
syncColumns();
document.addEventListener("change", syncColumns);
document.querySelectorAll("form[data-edit-form]").forEach(form => {
  const actions = form.querySelector(".form-actions");
  if (form.hasAttribute("data-unsaved-draft") && form.querySelector('input:not([type="hidden"]), select, textarea')) dirty.add(form);
  if (actions && form.querySelector('input:not([type="hidden"]), select, textarea')) {
    const status = document.createElement("span");
    status.dataset.formStatus = "";
    status.className = "form-status";
    status.setAttribute("role", "status");
    actions.append(status);
    updateDirty(form);
  }
  form.addEventListener("change", () => { dirty.add(form); updateDirty(form); });
  form.addEventListener("input", () => { dirty.add(form); updateDirty(form); });
  form.addEventListener("reset", () => {
    if (!form.hasAttribute("data-unsaved-draft")) dirty.delete(form);
    updateDirty(form);
    announce(labels.reset);
    setTimeout(syncColumns, 0);
  });
});
document.addEventListener("submit", event => {
  const form = event.target;
  if (event.defaultPrevented || form.hasAttribute("data-import-form")) return;
  if (pendingForms.has(form)) { event.preventDefault(); return; }
  const submitter = event.submitter;
  pendingForms.set(form, submitter);
  form.setAttribute("aria-busy", "true");
  // Disabling a submitter would remove its name/value from the native POST.
  if (submitter) {
    submitter.setAttribute("aria-disabled", "true");
    submitter.classList.add("is-working");
  }
  beginNavigation(form.method === "get" ? labels.loading : labels.working);
});
document.addEventListener("invalid", event => {
  const details = event.target.closest("details:not([open])");
  if (details) details.open = true;
  announce(labels.invalid, "error");
}, true);
document.addEventListener("click", event => {
  const refresh = event.target.closest("[data-refresh]");
  if (refresh) { beginNavigation(labels.loading); location.reload(); return; }
  const button = event.target.closest('button[aria-disabled="true"]');
  if (button) { event.preventDefault(); return; }
  const link = event.target.closest("a[href]");
  if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === "_blank") return;
  const target = new URL(link.href, location.href);
  if (target.origin !== location.origin) return;
  if (link.hasAttribute("download") || target.searchParams.has("download")) {
    announce(labels.download);
  } else if (target.pathname !== location.pathname || target.search !== location.search) {
    beginNavigation(labels.loading);
  }
});
const menu = document.querySelector(".menu-toggle");
if (menu) {
  menu.addEventListener("click", () => {
    const open = menu.getAttribute("aria-expanded") !== "true";
    menu.setAttribute("aria-expanded", String(open));
    menu.textContent = open ? labels.close : labels.menu;
  });
  document.addEventListener("keydown", event => {
    if (event.key === "Escape" && menu.getAttribute("aria-expanded") === "true") {
      menu.click(); menu.focus();
    }
  });
}
const serverNotice = document.querySelector("main > .notice");
if (serverNotice) {
  serverNotice.setAttribute("tabindex", "-1");
  serverNotice.focus();
}
window.addEventListener("pageshow", restoreInteraction);
window.addEventListener("beforeunload", event => {
  if ([...dirty].some(form => !pendingForms.has(form))) {
    event.preventDefault();
    event.returnValue = "";
    // A cancelled leave keeps the current document and its unsaved state.
    setTimeout(restoreInteraction, 0);
  }
});

const historyRequests = new Map();
async function syncHistory(select) {
  const box = select.closest("td").querySelector("[data-column-history]");
  if (!box || box.dataset.column === select.value) return;
  const column = select.value;
  box.dataset.column = column;
  box.replaceChildren();
  if (!column || column === "-" || column === "__new__") return;
  box.textContent = document.body.dataset.historyLoading;
  const archive = select.form.querySelector('input[name="archive"]').value;
  const url = new URL(location.href);
  url.search = new URLSearchParams({page: "fields", archive, column, format: "column", lang: document.documentElement.lang});
  try {
    if (!historyRequests.has(url.href)) {
      historyRequests.set(url.href, fetch(url, {credentials: "same-origin"}).then(response => {
        if (!response.ok) throw new Error("history unavailable");
        return response.json();
      }));
    }
    const data = await historyRequests.get(url.href);
    if (box.dataset.column !== column) return;
    box.replaceChildren();
    const warning = data.occupied && column !== select.dataset.originalValue;
    const contents = warning ? document.createElement("div") : box;
    if (warning) {
      contents.className = "existing-data-warning";
      contents.setAttribute("role", "status");
      const title = document.createElement("strong");
      title.className = "warning-title";
      title.textContent = data.warning;
      contents.append(title);
      box.append(contents);
    }
    const summary = document.createElement("strong");
    summary.className = "history-count";
    summary.textContent = data.summary;
    contents.append(summary);
    const list = document.createElement("dl");
    data.items.forEach(item => {
      const label = document.createElement("dt"), value = document.createElement("dd");
      label.textContent = item.label;
      value.textContent = item.value;
      list.append(label, value);
    });
    contents.append(list);
    if (data.sourceSummary) {
      const source = document.createElement("span");
      source.className = "history-source";
      source.textContent = data.sourceSummary;
      contents.append(source);
    }
    if (data.sources.length) {
      const details = document.createElement("details"), title = document.createElement("summary"), sources = document.createElement("ul");
      title.textContent = data.historyLabel;
      data.sources.forEach(text => {
        const item = document.createElement("li");
        item.textContent = text;
        sources.append(item);
      });
      details.append(title, sources);
      contents.append(details);
    }
    if (warning) {
      const label = document.createElement("label"), confirm = document.createElement("input");
      label.className = "check history-confirmation";
      confirm.type = "checkbox";
      confirm.name = select.dataset.confirmationName;
      confirm.value = column;
      confirm.required = true;
      label.append(confirm, document.createTextNode(data.confirmation));
      contents.append(label);
    }
  } catch (_) {
    historyRequests.delete(url.href);
    if (box.dataset.column === column) box.textContent = document.body.dataset.historyError;
  }
}
document.addEventListener("change", event => {
  if (event.target.matches("select[data-column-target]")) syncHistory(event.target);
});
document.addEventListener("reset", () => setTimeout(() => {
  document.querySelectorAll("select[data-column-target]").forEach(syncHistory);
}, 0));

const lastKinds = new Set((document.body.dataset.lastKinds || "").split(","));
document.addEventListener("change", event => {
  if (!event.target.matches('.column-draft select[name$="[kind]"]')) return;
  const aggregation = event.target.closest(".column-draft").querySelector('select[name$="[aggregation]"]');
  const last = lastKinds.has(event.target.value);
  Array.from(aggregation.options).forEach(option => { option.disabled = last && option.value !== "last"; });
  if (last) aggregation.value = "last";
});
