"use strict";

(() => {
  const box = document.querySelector("[data-archive-import]");
  if (!box) return;
  const labels = JSON.parse(box.dataset.labels);
  const status = box.querySelector("[data-import-status]");
  const fileInput = box.querySelector("[data-import-file]");
  const form = box.querySelector("[data-import-form]");
  const zone = form.elements.timezone;
  const allZones = [...zone.options].map(option => ({value: option.value, text: option.text}));
  const results = box.querySelector("[data-import-results]");
  const progressBox = box.querySelector(".import-progress");
  const progress = progressBox.querySelector("progress");
  const pause = box.querySelector("[data-import-pause]");
  const discard = box.querySelector("[data-import-discard]");
  const key = "weewx-archive-import:" + location.pathname;
  let current = null, file = null, paused = false, running = false, fingerprint = "";
  const save = () => { try { sessionStorage.setItem(key, JSON.stringify({id: current?.id, fingerprint})); } catch {} };
  const message = text => { status.textContent = text; status.classList.remove("import-error"); };
  const failure = error => { status.textContent = error.message || labels.failed; status.classList.add("import-error"); };
  const sources = disabled => {
    box.querySelectorAll(".import-sources button,.import-sources input").forEach(element => { element.disabled = disabled; });
    discard.hidden = !current;
    discard.disabled = disabled;
  };

  async function api(action, data = {}, bytes = null) {
    const url = new URL("import.php", document.baseURI);
    url.search = new URLSearchParams({action, lang: document.documentElement.lang, ...(bytes ? {id: data.id, offset: String(data.offset)} : {})});
    const response = await fetch(url, {method: "POST", credentials: "same-origin", headers: {"X-CSRF-Token": box.dataset.csrf, "Content-Type": bytes ? "application/octet-stream" : "application/json"}, body: bytes || JSON.stringify(data)});
    let result;
    try { result = await response.json(); } catch { throw new Error(labels.failed); }
    if (!response.ok) throw new Error(result.error || labels.failed);
    return result;
  }
  const size = bytes => new Intl.NumberFormat(document.documentElement.lang, {maximumFractionDigits: 1}).format(bytes / 1048576) + " MB";
  function ready(data) {
    current = data;
    form.hidden = false;
    discard.hidden = false;
    setZones(allZones, zone.value);
    box.querySelector("[data-import-all-zones]").hidden = true;
    form.elements.name.value = data.name.replace(/\.(sdb|db|sqlite|sqlite3)$/i, "");
    box.querySelector("[data-import-summary]").textContent = `${data.name} · ${size(data.size)} · ${data.units}`;
    progressBox.hidden = true;
    message(labels.ready);
    save();
  }
  function done(data) {
    try { sessionStorage.removeItem(key); } catch {}
    message(labels.complete);
    location.href = "?" + new URLSearchParams({page: "archives", archive: data.archive, lang: document.documentElement.lang, saved: "1"});
  }
  async function transfer() {
    if (running || !file) return;
    running = true; paused = false;
    sources(true); pause.textContent = labels.pause; progressBox.hidden = false;
    try {
      let retries = 0;
      while (current.received < file.size && !paused) {
        message(`${labels.uploading} ${size(current.received)} / ${size(file.size)}`);
        try {
          const next = await api("chunk", {id: current.id, offset: current.received}, file.slice(current.received, current.received + 1048576));
          current.received = next.received;
          progress.value = current.received / file.size * 100;
          retries = 0;
        } catch (error) {
          if (++retries > 3) throw error;
          await new Promise(resolve => setTimeout(resolve, retries * 700));
          current = await api("status", {id: current.id});
        }
      }
      if (!paused) { message(labels.checking); ready(await api("inspect", {id: current.id})); }
    } catch (error) { paused = true; failure(error); }
    finally { running = false; sources(false); if (paused) { pause.textContent = labels.resume; if (!status.classList.contains("import-error")) message(labels.paused); } }
  }
  fileInput.addEventListener("change", async () => {
    if (!fileInput.files.length || running) return;
    file = fileInput.files[0]; sources(true); form.hidden = true; results.replaceChildren(); message(labels.checking);
    try {
      const sample = new Uint8Array(await new Blob([file.slice(0,65536), file.slice(Math.max(0,file.size-65536))]).arrayBuffer());
      const digest = [...new Uint8Array(await crypto.subtle.digest("SHA-256", sample))].map(byte => byte.toString(16).padStart(2,"0")).join("");
      fingerprint = JSON.stringify([file.name,file.size,file.lastModified,digest]);
      let saved;
      try { saved = JSON.parse(sessionStorage.getItem(key)); } catch {}
      const previousId = current?.id || saved?.id;
      current = null;
      if (saved?.fingerprint === fingerprint) {
        try { current = await api("status", {id:saved.id}); } catch {}
      }
      if (!current || current.phase !== "upload") {
        current = await api("begin", {name:file.name,size:file.size});
        if (previousId && previousId !== current.id) await api("discard", {id:previousId});
      }
      save();
      await transfer();
    } catch (error) { failure(error); sources(false); }
  });
  box.querySelector("[data-import-search]").addEventListener("click", async () => {
    if (running) return;
    running = true; sources(true); results.replaceChildren(); message(labels.searching);
    try {
      let scan = await api("search");
      while (!scan.done) { scan = await api("search", {id:scan.id}); message(`${labels.searching} ${scan.files.length}`); }
      if (!scan.files.length) message(labels.no_files); else message("");
      if (scan.limited) message(labels.limited);
      for (const entry of scan.files) {
        const button = document.createElement("button"); button.type = "button"; button.className = "import-result";
        const title = document.createElement("span"), detail = document.createElement("small");
        title.textContent = entry.label; detail.textContent = size(entry.size); button.append(title,detail);
        button.addEventListener("click", async () => {
          if (running) return;
          running = true; sources(true); message(labels.checking);
          try {
            const selected = await api("select", {search:scan.id,key:entry.key});
            if (current?.id) await api("discard", {id:current.id});
            fingerprint = ""; current = selected; save();
            ready(await api("inspect", selected)); results.replaceChildren();
          } catch (error) { failure(error); }
          finally { running = false; sources(false); }
        });
        results.append(button);
      }
    } catch (error) { failure(error); }
    finally { running = false; sources(false); }
  });
  const setZones = (options, previous) => {
    zone.replaceChildren(...options.map(({value,text}) => new Option(text,value)));
    if (options.some(option => option.value === previous)) zone.value = previous;
    else { zone.prepend(new Option(labels.choose_zone,"",true,true)); }
    zone.required = true;
  };
  box.querySelector("[data-import-detect]").addEventListener("click", async event => {
    if (!current || running) return;
    const button = event.currentTarget; button.disabled = true; running = true; message(labels.checking);
    try {
      const detected = await api("detect", {id:current.id});
      if (!detected.zones.length) { setZones(allZones,zone.value); message(labels.no_zones); }
      else {
        setZones(allZones.filter(option => detected.zones.includes(option.value)),zone.value);
        message(`${detected.zones.length} ${labels.zones} · ${new Intl.NumberFormat(document.documentElement.lang).format(detected.milliseconds)} ms`);
        box.querySelector("[data-import-all-zones]").hidden = false;
      }
    } catch (error) { failure(error); }
    finally { button.disabled = false; running = false; }
  });
  box.querySelector("[data-import-all-zones]").addEventListener("click", event => { setZones(allZones,zone.value); event.currentTarget.hidden = true; message(labels.all_zones_shown); });
  async function repair() {
    if (running) return;
    running = true; paused = false; progressBox.hidden = false; pause.textContent = labels.pause; sources(true);
    form.querySelectorAll("button,input,select").forEach(element => { element.disabled = true; });
    try {
      while (["repair", "publishing"].includes(current.phase) && !paused) {
        message(`${labels.repairing} ${current.progress || 0} %`); progress.value = current.progress || 0;
        current = await api("step", {id:current.id}); save();
      }
      if (current.phase === "complete") done(current);
    } catch (error) { paused = true; failure(error); }
    finally { running = false; if (paused) { pause.textContent = labels.resume; discard.disabled = false; if (!status.classList.contains("import-error")) message(labels.paused); } }
  }
  pause.addEventListener("click", () => {
    if (running) { paused = true; message(labels.pausing); }
    else if (["repair", "publishing"].includes(current?.phase)) repair();
    else transfer();
  });
  form.addEventListener("submit", async event => {
    event.preventDefault(); if (!current || running) return;
    running = true; sources(true); message(labels.checking);
    const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
    try {
      current = await api("commit", {id:current.id,name:form.elements.name.value,timezone:zone.value}); save();
      if (current.phase === "complete") done(current);
    } catch (error) { failure(error); }
    finally { running = false; submit.disabled = false; sources(false); }
    if (["repair", "publishing"].includes(current?.phase)) repair();
  });
  discard.addEventListener("click", async () => {
    if (!current || running) return;
    discard.disabled = true; message(labels.discarding);
    try {
      await api("discard", {id:current.id});
      try { sessionStorage.removeItem(key); } catch {}
      current = null; file = null; fileInput.value = "";
      form.hidden = true; progressBox.hidden = true; results.replaceChildren();
      form.querySelectorAll("button,input,select").forEach(element => { element.disabled = false; });
      sources(false); message(labels.discarded);
    } catch (error) { discard.disabled = false; failure(error); }
  });
  (async () => {
    let saved; try { saved = JSON.parse(sessionStorage.getItem(key)); } catch {}
    if (!saved?.id) return;
    try {
      current = await api("status", {id:saved.id}); fingerprint = saved.fingerprint;
      if (current.phase === "ready") ready(current);
      else if (["repair", "publishing"].includes(current.phase)) repair();
      else if (current.phase === "upload") { message(labels.resume_select); sources(false); }
      else if (current.phase === "uploaded") ready(await api("inspect", {id:current.id}));
      else if (current.phase === "complete") done(current);
    } catch {}
  })();
})();
