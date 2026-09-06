"use strict";
(() => {
  const box = document.querySelector('[data-location-search]');
  if (!box) return;
  const form = box.closest('form'), labels = JSON.parse(box.dataset.labels);
  const query = box.querySelector('[data-location-query]'), button = box.querySelector('[data-location-submit]');
  const results = box.querySelector('[data-location-results]'), status = box.querySelector('[data-location-status]');
  async function search() {
    if (button.disabled) return;
    if (query.value.trim().length < 2) {
      status.textContent = labels.invalid;
      query.setAttribute('aria-invalid', 'true'); query.focus();
      return;
    }
    query.removeAttribute('aria-invalid');
    button.disabled = true; button.classList.add('is-working'); box.setAttribute('aria-busy', 'true');
    results.replaceChildren(); status.textContent = labels.searching;
    try {
      const response = await fetch('?' + new URLSearchParams({page:'archives',lang:document.documentElement.lang}), {
        method:'POST', credentials:'same-origin', body:new URLSearchParams({action:'location.search',csrf:form.elements.csrf.value,query:query.value.trim()})
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || labels.failed);
      status.textContent = data.results.length ? '' : labels.empty;
      for (const place of data.results) {
        const choice = document.createElement('button'); choice.type = 'button';
        const name = document.createElement('strong'), detail = document.createElement('small');
        name.textContent = place.label;
        detail.textContent = `${place.latitude}°, ${place.longitude}°` + (place.elevation == null ? '' : ` · ${place.elevation} m`);
        choice.append(name,detail);
        choice.addEventListener('click', () => {
          form.elements.location.value = place.name;
          form.elements.latitude.value = place.latitude;
          form.elements.longitude.value = place.longitude;
          if (!form.elements.altitude_value.value && place.elevation != null) {
            form.elements.altitude_value.value = place.elevation;
            form.elements.altitude_unit.value = 'meter';
          }
          if (form.elements.timezone && place.timezone) form.elements.timezone.value = place.timezone;
          form.elements.latitude.dispatchEvent(new Event('input',{bubbles:true}));
          results.replaceChildren(); status.textContent = labels.applied;
        });
        results.append(choice);
      }
    } catch (error) { status.textContent = error.message || labels.failed; }
    finally { button.disabled = false; button.classList.remove('is-working'); box.removeAttribute('aria-busy'); }
  }
  button.addEventListener('click',search);
  query.addEventListener('keydown',event => { if (event.key === 'Enter') { event.preventDefault(); search(); } });
  form.addEventListener('reset',() => { results.replaceChildren(); status.textContent = ''; });
})();
