const form = document.getElementById('keywordForm');
const input = document.getElementById('keywords');
const spinner = document.getElementById('spinnerOverlay');
const resultsContent = document.getElementById('resultsContent');
const resultsPlaceholder = document.getElementById('resultsPlaceholder');
const resetButton = document.getElementById('resetCache');
const keywordCount = document.getElementById('keywordCount');
const recentReportsContent = document.getElementById('recentReportsContent');
let contentInput = null;
let contentResult = null;
let contentPreview = null;
let currentReportHash = null;

const showSpinner = (show) => {
  spinner.classList.toggle('hidden', !show);
};

const formatNumber = (value) => {
  if (value === null || value === undefined) return '-';
  if (typeof value === 'number') return value.toLocaleString('es-ES');
  return value;
};

const createCard = (title, subtitle, bodyHtml, open = true) => {
  const details = document.createElement('details');
  details.className = 'card';
  details.open = open;
  const summary = document.createElement('summary');
  summary.innerHTML = `${title} <span>${subtitle || ''}</span>`;
  const body = document.createElement('div');
  body.innerHTML = bodyHtml;
  details.appendChild(summary);
  details.appendChild(body);
  return details;
};

const renderKeywords = (analysis, tfidf) => {
  const terms = analysis?.keywords_semanticas || [];
  const fallback = tfidf?.terms || [];
  const rows = (terms.length ? terms : fallback.slice(0, 20)).map((term) => {
    const name = term.term || '';
    const score = term.tfidf_score ?? term.avg_score ?? term.max_score ?? '';
    const densidad = term.densidad_recomendada || '-';
    const menciones = term.menciones_sugeridas || '-';
    return `<tr><td>${name}</td><td>${score}</td><td>${densidad}</td><td>${menciones}</td></tr>`;
  }).join('');

  return `
    <table>
      <thead>
        <tr><th>Termino</th><th>TF-IDF</th><th>Densidad</th><th>Menciones</th></tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
  `;
};

const renderLongitud = (analysis, stats) => {
  const data = analysis?.longitud_texto || {};
  return `
    <div class="list-grid">
      <div><strong>Promedio top (palabras)</strong><br>${formatNumber(data.promedio_palabras || stats?.avg_words || '-')}</div>
      <div><strong>Rango recomendado (palabras)</strong><br>${data.rango_recomendado || '-'}</div>
    </div>
  `;
};

const renderIntencion = (analysis) => {
  const data = analysis?.intencion_tono || {};
  return `
    <div class="list-grid">
      <div><strong>Intencion</strong><br>${data.intencion || '-'}</div>
      <div><strong>Tono</strong><br>${data.tono || '-'}</div>
      <div><strong>Profundidad</strong><br>${data.nivel_profundidad || '-'}</div>
      <div><strong>Contexto emocional</strong><br>${data.contexto_emocional || '-'}</div>
    </div>
  `;
};

const renderClusters = (analysis) => {
  const clusters = analysis?.clusters_seo || [];
  if (!clusters.length) return '<p>No se encontraron clusters.</p>';

  return clusters.map((cluster) => {
    const score = Math.min(100, Math.max(0, cluster.salient_score || 0));
    const subtemas = (cluster.subtemas || []).map((item) => `<span class="badge-pill">${item}</span>`).join('');
    return `
      <div class="card" style="margin-top:12px;">
        <div><strong>${cluster.cluster || '-'}</strong> (${cluster.cobertura_top || '-'})</div>
        <div class="progress-bar"><span style="width:${score}%"></span></div>
        <div style="margin-top:8px; color: #667085;">Profundidad sugerida: ${formatNumber(cluster.profundidad_palabras || '-')} palabras</div>
        <div class="badges">${subtemas || ''}</div>
      </div>
    `;
  }).join('');
};

const renderEstructura = (analysis) => {
  const data = analysis?.estructura_propuesta || {};
  const sections = data.secciones || [];
  const rows = sections.map((sec) => {
    const h3 = (sec.h3 || []).join(', ');
    return `<tr><td>${sec.orden || '-'}</td><td>${sec.h2 || '-'}</td><td>${h3 || '-'}</td><td>${formatNumber(sec.longitud_palabras || '-')}</td></tr>`;
  }).join('');

  return `
    <div><strong>H1 sugerido:</strong> ${data.h1 || '-'}</div>
    <table>
      <thead>
        <tr><th>Orden</th><th>H2</th><th>H3</th><th>Longitud</th></tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
  `;
};

const renderOportunidades = (analysis) => {
  const data = analysis?.oportunidades || {};
  const blocks = [
    { label: 'Content gaps', items: data.content_gaps || [] },
    { label: 'Formatos ausentes', items: data.formatos_ausentes || [] },
    { label: 'Preguntas sin responder', items: data.preguntas_sin_responder || [] },
    { label: 'Ideas para diferenciarse', items: data.ideas_diferenciacion || [] },
  ];

  return blocks.map((block) => {
    const list = block.items.length ? `<ul>${block.items.map((item) => `<li>${item}</li>`).join('')}</ul>` : '<p>-</p>';
    return `<div><strong>${block.label}</strong>${list}</div>`;
  }).join('');
};

const renderSources = (payload) => {
  const docs = (payload?.documents || []).filter((doc) => doc.source_type !== 'serp_snippet');
  if (!docs.length) return '<p>-</p>';
  const fallback = payload?.fallback_mode ? `<p><strong>Modo:</strong> ${payload.fallback_mode}</p>` : '';
  const items = docs.map((doc) => {
    const title = doc.title || doc.url || 'Sin titulo';
    const url = doc.url || '';
    const keyword = doc.keyword ? `<div><strong>Keyword:</strong> ${doc.keyword}</div>` : '';
    const wordCount = doc.word_count ?? '-';
    const tone = doc.tone || '-';
    const terms = Array.isArray(doc.top_terms) && doc.top_terms.length ? doc.top_terms.join(', ') : '-';
    const sourceType = doc.source_type || '-';
    const header = url
      ? `<a href="${url}" target="_blank" rel="noopener noreferrer">${title}</a>`
      : `${title}`;
    return `
      <li>
        <div>${header}</div>
        ${keyword}
        <div><strong>Fuente:</strong> ${sourceType}</div>
        <div><strong>Contenido:</strong> ${wordCount} palabras</div>
        <div><strong>Tono:</strong> ${tone}</div>
        <div><strong>Top terminos:</strong> ${terms}</div>
      </li>
    `;
  }).join('');
  return `${fallback}<ul>${items}</ul>`;
};

const renderContentGenerator = () => `
  <div class="checker-grid">
    <div class="checker-actions">
      <button type="button" class="button-secondary" data-content="adjust">Ajustar contenido</button>
    </div>
    <textarea class="prompt-box" data-content="input" rows="12" placeholder="AquÃ­ aparecerÃ¡ el HTML generado"></textarea>
    <div class="checker-preview" data-content="preview">
      <p>Vista previa del HTML.</p>
    </div>
    <div class="checker-result" data-content="result">
      <p>Genera el contenido para ver el anÃ¡lisis de cumplimiento.</p>
    </div>
  </div>
`;

const renderCheckerResult = (payload) => {
  if (payload.error) {
    contentResult.innerHTML = `<p><strong>Error:</strong> ${payload.error}</p>`;
    return;
  }
  const score = payload.score ?? 0;
  const wordCount = payload.word_count ?? 0;
  const minReq = payload.min_required ?? 0;
  const insights = payload.insights || [];
  const terms = payload.term_stats || [];
  const insightsHtml = insights.length ? `<ul>${insights.map((item) => `<li>${item}</li>`).join('')}</ul>` : '<p>-</p>';
  const rows = terms.map((t) => `
    <tr>
      <td>${t.term}</td>
      <td>${t.density}%</td>
      <td>${t.target || '-'}</td>
      <td>${t.occurrences}</td>
      <td>${t.ok ? 'OK' : 'Ajustar'}</td>
    </tr>
  `).join('');
  contentResult.innerHTML = `
    <div><strong>Puntuacion</strong> <span class="score-pill">${score}/100</span></div>
    <div><strong>Palabras</strong> ${wordCount} (minimo ${minReq})</div>
    <div style="margin-top:8px;"><strong>Insights</strong>${insightsHtml}</div>
    <table class="checker-table">
      <thead>
        <tr><th>Termino</th><th>Densidad</th><th>Objetivo</th><th>Ocurrencias</th><th>Estado</th></tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
  `;
};

const renderUsage = (payload) => {
  const usage = payload?.usage || {};
  if (!Object.keys(usage).length) return '<p>-</p>';
  const provider = usage.llm_provider ? usage.llm_provider.toUpperCase() : 'LLM';
  const serpapi = usage.serpapi_calls ?? '-';
  const tokens = usage.openai_tokens ?? '-';
  const urls = usage.urls_total ?? '-';
  const scraped = usage.scraped_ok ?? '-';
  const serpapiCost = usage.serpapi_cost_usd ?? '-';
  const llmCost = usage.openai_cost_usd ?? '-';
  const serpRate = usage.serpapi_rate_usd ?? null;
  const openaiRate = usage.openai_rate_usd_per_1m ?? null;
  const noteParts = [];
  if (serpRate !== null) noteParts.push(`SerpAPI ~$${serpRate}/search`);
  if (openaiRate !== null) noteParts.push(`OpenAI ~$${openaiRate}/1M tokens`);
  const noteText = noteParts.length ? `${noteParts.join(' - ')}` : '';
  const note = usage.pricing_note || noteText
    ? `<div><strong>Nota</strong><br>${[usage.pricing_note, noteText].filter(Boolean).join(' - ')}</div>`
    : '';
  return `
    <div class="list-grid">
      <div><strong>SerpAPI</strong><br>${serpapi}</div>
      <div><strong>${provider} tokens</strong><br>${tokens}</div>
      <div><strong>URLs</strong><br>${urls}</div>
      <div><strong>Scraped</strong><br>${scraped}</div>
      <div><strong>SerpAPI USD</strong><br>${serpapiCost}</div>
      <div><strong>${provider} USD</strong><br>${llmCost}</div>
      ${note}
    </div>
  `;
};

const renderResults = (payload) => {
  resultsContent.innerHTML = '';
  resultsPlaceholder.style.display = 'none';

  if (payload.error) {
    resultsContent.innerHTML = `<div class="card"><strong>Error:</strong> ${payload.error}</div>`;
    return;
  }

  currentReportHash = payload.keyword_hash || currentReportHash;
  const analysis = payload.analysis || {};
  const tfidf = payload.tfidf || {};

  if (analysis.fallback) {
    resultsContent.appendChild(createCard('Aviso', '', '<p>El analisis de IA no estuvo disponible. Se muestran datos TF-IDF como referencia.</p>', true));
  }

  resultsContent.appendChild(createCard('1. Keywords semanticas', payload.cached ? 'cache' : 'nuevo', renderKeywords(analysis, tfidf)));
  resultsContent.appendChild(createCard('2. Longitud de texto', '', renderLongitud(analysis, payload.stats)));
  resultsContent.appendChild(createCard('3. Intencion y tono', '', renderIntencion(analysis)));
  resultsContent.appendChild(createCard('4. Clusters SEO', '', renderClusters(analysis)));
  resultsContent.appendChild(createCard('5. Estructura propuesta', '', renderEstructura(analysis)));
  resultsContent.appendChild(createCard('6. Oportunidades', '', renderOportunidades(analysis)));
  resultsContent.appendChild(createCard('7. Fuentes analizadas', '', renderSources(payload), false));
  resultsContent.appendChild(createCard('8. Contenido generado', '', renderContentGenerator(), true));
  resultsContent.appendChild(createCard('9. Consumo APIs', '', renderUsage(payload), false));

  contentInput = resultsContent.querySelector('[data-content="input"]');
  contentResult = resultsContent.querySelector('[data-content="result"]');
  contentPreview = resultsContent.querySelector('[data-content="preview"]');
  const adjustBtn = resultsContent.querySelector('[data-content="adjust"]');
  const updatePreview = () => {
    if (!contentPreview) return;
    const html = (contentInput?.value || '').trim();
    contentPreview.innerHTML = html ? html : '<p>Vista previa del HTML.</p>';
  };
  if (contentInput) {
    contentInput.addEventListener('input', updatePreview);
    updatePreview();
  }
  const evaluateContent = async () => {
    if (!currentReportHash) {
      contentResult.innerHTML = '<p>Selecciona un informe primero.</p>';
      return;
    }
    const html = (contentInput?.value || '').trim();
    if (!html) {
      contentResult.innerHTML = '<p>Introduce HTML para evaluar.</p>';
      return;
    }
    const response = await fetch('/api/check-content.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ html, keyword_hash: currentReportHash })
    });
    const payload = await response.json();
    renderCheckerResult(payload);
  };
  const generateContent = async () => {
    if (!currentReportHash) {
      contentResult.innerHTML = '<p>Selecciona un informe primero.</p>';
      return;
    }
    showSpinner(true);
    try {
      const response = await fetch('/api/generate-content.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ keyword_hash: currentReportHash })
      });
      if (!response.ok) {
        const text = await response.text();
        contentResult.innerHTML = `<p><strong>Error:</strong> ${text || 'No se pudo generar.'}</p>`;
        return;
      }
      const payload = await response.json();
      if (payload.html) {
        contentInput.value = payload.html;
        updatePreview();
        await evaluateContent();
      } else {
        contentResult.innerHTML = `<p><strong>Error:</strong> ${payload.error || 'No se pudo generar.'}</p>`;
      }
    } catch (error) {
      contentResult.innerHTML = '<p>No se pudo generar el contenido.</p>';
    } finally {
      showSpinner(false);
    }
  };
  generateContent();
  if (adjustBtn) {
    adjustBtn.addEventListener('click', async () => {
      if (!currentReportHash) {
        contentResult.innerHTML = '<p>Selecciona un informe primero.</p>';
        return;
      }
      const html = (contentInput?.value || '').trim();
      if (!html) {
        contentResult.innerHTML = '<p>Introduce el HTML a ajustar.</p>';
        return;
      }
      const confirmed = confirm('Este ajuste consume API de IA. ?Continuar?');
      if (!confirmed) return;
      showSpinner(true);
      try {
        const response = await fetch('/api/adjust-content.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ html, keyword_hash: currentReportHash })
        });
        const payload = await response.json();
        if (payload.adjusted_html) {
          contentInput.value = payload.adjusted_html;
          updatePreview();
          await evaluateContent();
        } else {
          contentResult.innerHTML = `<p><strong>Error:</strong> ${payload.error || 'No se pudo ajustar.'}</p>`;
        }
      } catch (error) {
        contentResult.innerHTML = '<p>No se pudo ajustar el contenido.</p>';
      } finally {
        showSpinner(false);
      }
    });
  }
};

const getKeywordList = (value) => value.split(',').map((k) => k.trim()).filter(Boolean);

const updateKeywordCount = () => {
  if (!keywordCount) return;
  const count = getKeywordList(input.value).length;
  keywordCount.textContent = `${count}/3 keywords detectadas`;
};

const loadRecentReports = async () => {
  if (!recentReportsContent) return;
  recentReportsContent.innerHTML = '<p>Cargando...</p>';
  try {
    const response = await fetch('/api/recent-reports.php');
    const payload = await response.json();
    const items = payload.items || [];
    if (!items.length) {
      recentReportsContent.innerHTML = '<p>No hay informes recientes.</p>';
      return;
    }
    const rows = items.map((item) => {
      const keywords = item.keywords || '-';
      const created = item.created_at || '';
      return `
        <li class="report-item">
          <div>
            <strong>${keywords}</strong><br>
            <span>${created}</span>
          </div>
          <div class="report-actions">
            <button class="button-link" data-run="${item.keyword_hash}">Cargar</button>
            <button class="button-link" data-delete="${item.id}">Eliminar</button>
          </div>
        </li>
      `;
    }).join('');
    recentReportsContent.innerHTML = `
      <div class="report-toolbar">
        <button class="button-link" data-load="all">Ver todos</button>
      </div>
      <ul class="report-list">${rows}</ul>
    `;
  } catch (error) {
    recentReportsContent.innerHTML = '<p>No se pudo cargar el historial.</p>';
  }
};

const renderReportList = (items) => {
  const rows = items.map((item) => {
    const keywords = item.keywords || '-';
    const created = item.created_at || '';
    return `
      <li class="report-item">
        <div>
          <strong>${keywords}</strong><br>
          <span>${created}</span>
        </div>
        <div class="report-actions">
          <button class="button-link" data-run="${item.keyword_hash}">Cargar</button>
          <button class="button-link" data-delete="${item.id}">Eliminar</button>
        </div>
      </li>
    `;
  }).join('');
  return `<ul class="report-list">${rows}</ul>`;
};

const attachRecentReportHandlers = () => {
  if (!recentReportsContent) return;
  recentReportsContent.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const loadAll = target.getAttribute('data-load');
    const hash = target.getAttribute('data-run');
    const deleteId = target.getAttribute('data-delete');
    if (loadAll === 'all') {
      recentReportsContent.innerHTML = '<p>Cargando...</p>';
      try {
        const response = await fetch('/api/all-reports.php?limit=200');
        const payload = await response.json();
        const items = payload.items || [];
        if (!items.length) {
          recentReportsContent.innerHTML = '<p>No hay informes.</p>';
          return;
        }
        recentReportsContent.innerHTML = `
          <div class="report-toolbar">
            <button class="button-link" data-load="recent">Ver recientes</button>
          </div>
          ${renderReportList(items)}
        `;
      } catch (error) {
        recentReportsContent.innerHTML = '<p>No se pudo cargar el historial.</p>';
      }
      return;
    }
    if (loadAll === 'recent') {
      loadRecentReports();
      return;
    }
    if (hash) {
      showSpinner(true);
      try {
        const response = await fetch('/api/analyze.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ keyword_hash: hash })
        });
        const payload = await response.json();
        renderResults(payload);
      } catch (error) {
        renderResults({ error: 'No se pudo cargar el informe.' });
      } finally {
        showSpinner(false);
      }
    }
    if (deleteId) {
      const confirmed = confirm('Eliminar este informe?');
      if (!confirmed) return;
      try {
        const response = await fetch('/api/delete-report.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: Number(deleteId) })
        });
        const payload = await response.json();
        if (!payload.ok) {
          alert(payload.error || 'No se pudo eliminar.');
        }
      } catch (error) {
        alert('No se pudo eliminar.');
      } finally {
        loadRecentReports();
      }
    }
  });
};

input.addEventListener('input', updateKeywordCount);
updateKeywordCount();
loadRecentReports();
attachRecentReportHandlers();

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const keywords = input.value.trim();
  if (!keywords) return;

  const keywordList = getKeywordList(keywords);
  if (keywordList.length > 3) {
    alert('Introduce un maximo de 3 palabras clave.');
    return;
  }

  showSpinner(true);

  try {
    const response = await fetch('/api/analyze.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ keywords })
    });

    const payload = await response.json();
    renderResults(payload);
    loadRecentReports();
  } catch (error) {
    renderResults({ error: 'No se pudo completar el analisis.' });
  } finally {
    showSpinner(false);
  }
});

if (resetButton) {
  resetButton.addEventListener('click', async () => {
    const confirmed = confirm('Esto borrara el cache y el historial de analisis. ?Continuar?');
    if (!confirmed) return;
    showSpinner(true);
    try {
      const response = await fetch('/api/clear-cache.php', { method: 'POST' });
      const payload = await response.json();
      if (payload.ok) {
        alert('Cache limpiada.');
      } else {
        alert(payload.error || 'No se pudo limpiar la cache.');
      }
    } catch (error) {
      alert('No se pudo limpiar la cache.');
    } finally {
      showSpinner(false);
    }
  });
}

