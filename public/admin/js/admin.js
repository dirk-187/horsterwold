/**
 * Admin Dashboard Logic for Horsterwold
 * Volledig herschreven voor beheer van magic links, statussen en afwijkingen
 */

// Vang onverwachte JS-fouten op
window.addEventListener('error', function(e) {
    console.error('[ADMIN ERROR]', e.message, 'in', e.filename + ':' + e.lineno);
});
window.addEventListener('unhandledrejection', function(e) {
    console.error('[ADMIN PROMISE ERROR]', e.reason);
});


let allLots = [];
let afwijkingenData = [];
let currentLotId = null;
let currentOccupancyId = null; // Nieuw: tracker voor actieve occupancy in modal
let currentPreviewData = null;

/**
 * Veilige helper voor klembord (werkt alleen in Secure Context: HTTPS of localhost)
 */
function copyToClipboard(text) {
    // 1. Probeer de moderne Clipboard API (vereist Secure Context: HTTPS of localhost)
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            console.log('Gekopieerd via Clipboard API');
        }).catch(err => {
            console.warn('Clipboard API mislukt, fallback gebruiken...', err);
        });
        // We retourneren true omdat we de fallback ook direct proberen of de API aanroepen
    }
    
    // 2. Fallback voor non-secure contexts (HTTP via IP-adres)
    try {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        
        // Zorg dat het element niet zichtbaar is maar wel in de DOM staat
        textArea.style.position = "fixed";
        textArea.style.left = "-9999px";
        textArea.style.top = "0";
        document.body.appendChild(textArea);
        
        textArea.focus();
        textArea.select();
        
        const successful = document.execCommand('copy');
        document.body.removeChild(textArea);
        
        if (successful) return true;
    } catch (err) {
        console.error('Kopieer fallback mislukt:', err);
    }
    
    return false;
}

document.addEventListener('DOMContentLoaded', () => {
    // Stap 1: Check if er een token in de URL staat (admin magic link)
    const urlParams = new URLSearchParams(window.location.search);
    const token = urlParams.get('token');

    if (token) {
        window.history.replaceState({}, document.title, window.location.pathname);
        verifyAdminToken(token);
    } else {
        checkAdminSession();
    }
});

async function verifyAdminToken(token) {
    try {
        const response = await fetch('../../backend/api/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'verify', token })
        });
        
        const contentType = response.headers.get("content-type");
        if (contentType && contentType.indexOf("application/json") !== -1) {
            const data = await response.json();

            if (data.success && data.user?.role === 'admin') {
                showAdminDashboard();
            } else {
                showAdminLogin('Ongeldige of verlopen link. Vraag een nieuwe aan.');
            }
        } else {
            showAdminLogin('Verbindingsfout: Server gaf geen geldig antwoord.');
        }
    } catch (e) {
        showAdminLogin('Verbindingsfout bij het verifiëren van de link.');
    }
}

async function checkAdminSession() {
    try {
        const response = await fetch('../../backend/api/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check' })
        });
        
        const contentType = response.headers.get("content-type");
        if (contentType && contentType.indexOf("application/json") !== -1) {
            const data = await response.json();

            if (data.success && data.user?.role === 'admin') {
                showAdminDashboard();
            } else {
                showAdminLogin();
            }
        } else {
            showAdminLogin('Sessie verlopen of ongeldig.');
        }
    } catch (e) {
        showAdminLogin();
    }
}

function showAdminLogin(message = '') {
    const overlay = document.getElementById('admin-login-overlay');
    overlay.style.display = 'flex';

    if (message) {
        const fb = document.getElementById('admin-login-feedback');
        fb.textContent = message;
        fb.style.color = '#f87171';
    }

    // Hook up het login form
    const form = document.getElementById('admin-login-form');
    form.onsubmit = async (e) => {
        e.preventDefault();
        const email = document.getElementById('admin-email-input').value;
        const feedback = document.getElementById('admin-login-feedback');
        
        feedback.textContent = 'Bezig met verbinden...';
        feedback.style.color = 'var(--text-muted)';

        try {
            const response = await fetch('../../backend/api/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'request', email, is_admin: true })
            });
            
            const data = await response.json();

            if (data.success) {
                feedback.textContent = '✅ Controleer je inbox!';
                feedback.style.color = '#4ade80';
                
                if (data.debug_link) {
                    const box = document.getElementById('admin-debug-link-box');
                    const link = document.getElementById('admin-debug-link');
                    box.style.display = 'block';
                    link.textContent = data.debug_link;
                    link.href = data.debug_link;
                }
            } else {
                feedback.textContent = '❌ ' + (data.error || 'Onbekende fout');
                feedback.style.color = '#f87171';
            }
        } catch (err) {
            feedback.textContent = '❌ Verbindingsfout.';
            feedback.style.color = '#f87171';
        }
    };
}

function toggleModal(id, show = true) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = show ? 'flex' : 'none';
    document.body.classList.toggle('modal-open', show);
}

function closeModal() {
    toggleModal('modal-overlay', false);
}

// Sluiten bij klikken op overlay (backdrop)
document.addEventListener('click', (e) => {
    if (e.target.id === 'modal-overlay') {
        closeModal();
    }
});

function showAdminDashboard() {
    toggleModal('admin-login-overlay', false);

    // Laad het dashboard
    loadDashboard();
    
    // Filters & Zoeken
    const searchLot = document.getElementById('search-lot');
    if (searchLot) searchLot.addEventListener('input', applyFilters);
    
    document.querySelectorAll('input[name="filter-type"]').forEach(radio => {
        radio.addEventListener('change', applyFilters);
    });
    
    document.querySelectorAll('.filter-card').forEach(card => {
        card.addEventListener('click', (e) => {
            const targetCard = e.target.closest('.filter-card');
            if (!targetCard || targetCard.dataset.filter === 'betaalstatus') return;

            document.querySelectorAll('.filter-card').forEach(b => b.classList.remove('active'));
            targetCard.classList.add('active');
            applyFilters();
        });
    });

    // Live update when max-dev changes
    const paramMaxDev = document.getElementById('param-max-dev');
    if (paramMaxDev) {
        paramMaxDev.addEventListener('input', applyFilters);
    }

    // Nieuwe Bewoner Logic
    initNewResidentForm();

    // Start nieuwe afrekening (bulk versturen links)
    const btnNewBilling = document.getElementById('btn-new-billing');
    if (btnNewBilling) btnNewBilling.addEventListener('click', () => sendMagicLinkAll());


    // Facturen Modal
    const btnOpenInvoices = document.getElementById('btn-open-invoices');
    if (btnOpenInvoices) btnOpenInvoices.addEventListener('click', openInvoicesDashboard);

    const btnInvoicesClose = document.getElementById('invoices-close');
    if (btnInvoicesClose) btnInvoicesClose.addEventListener('click', () => toggleModal('invoices-modal-overlay', false));

    const btnStartBatch = document.getElementById('btn-start-batch-invoicing');
    if (btnStartBatch) btnStartBatch.addEventListener('click', startBatchInvoicing);

    // Modals
    const modalClose = document.getElementById('modal-close');
    if (modalClose) modalClose.addEventListener('click', closeModal);

    const confirmCancel = document.getElementById('confirm-cancel');
    if (confirmCancel) confirmCancel.addEventListener('click', closeConfirm);

    
    // Tabs in modal
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const tabId = e.target.dataset.tab;
            switchModalTab(tabId);
            if (tabId === 'tab-invoice') loadInvoicePreview(currentLotId);
        });
    });

    // Manual Input modal
    const manualClose = document.getElementById('manual-close');
    if (manualClose) manualClose.addEventListener('click', closeManualModal);

    const manualCancel = document.getElementById('manual-cancel');
    if (manualCancel) manualCancel.addEventListener('click', closeManualModal);

    const formManual = document.getElementById('form-manual-input');
    if (formManual) formManual.addEventListener('submit', submitManualInput);

    const manualPhoto = document.getElementById('manual-photo');
    if (manualPhoto) manualPhoto.addEventListener('change', handleManualPhotoChange);

    // Tariffs modal
    const btnOpenTariffs = document.getElementById('btn-open-tariffs');
    if (btnOpenTariffs) btnOpenTariffs.addEventListener('click', openTariffsModal);

    const tariffsClose = document.getElementById('tariffs-close');
    if (tariffsClose) tariffsClose.addEventListener('click', closeTariffsModal);

    const tariffsCancel = document.getElementById('tariffs-cancel');
    if (tariffsCancel) tariffsCancel.addEventListener('click', closeTariffsModal);

    const formTariffs = document.getElementById('form-tariffs');
    if (formTariffs) formTariffs.addEventListener('submit', submitTariffs);

    const btnFinalizeYear = document.getElementById('btn-finalize-year');
    if (btnFinalizeYear) btnFinalizeYear.addEventListener('click', finalizeYear);

    // Add/Edit Resident modal setup
    const formRes = document.getElementById('form-resident');
    if (formRes) formRes.addEventListener('submit', submitResident);

    // OCR Test Tool (File upload)
    const btnOcrFile = document.getElementById('btn-run-ocr-test');
    if (btnOcrFile) btnOcrFile.addEventListener('click', runManualOcrTest);

    // OCR Test Tool (Live scanner)
    const btnOcrScanner = document.getElementById('btn-open-admin-scanner');
    if (btnOcrScanner) {
        btnOcrScanner.addEventListener('click', () => {
            toggleModal('admin-scanner-overlay', true);
            startAdminScanner();
        });
    }

    // Admin User listeners
    const btnAddAdmin = document.getElementById('btn-show-add-admin');
    if (btnAddAdmin) btnAddAdmin.addEventListener('click', () => openAdminUserModal());
    
    const adminUserClose = document.getElementById('admin-user-close');
    if (adminUserClose) adminUserClose.addEventListener('click', () => toggleModal('admin-user-modal-overlay', false));
    
    const adminUserCancel = document.getElementById('admin-user-cancel');
    if (adminUserCancel) adminUserCancel.addEventListener('click', () => toggleModal('admin-user-modal-overlay', false));
    
    const formAdminUser = document.getElementById('form-admin-user');
    if (formAdminUser) formAdminUser.addEventListener('submit', saveAdminUser);

    // Admin Scanner listeners
    const adminScannerClose = document.getElementById('btn-admin-scanner-close');
    if (adminScannerClose) {
        adminScannerClose.addEventListener('click', () => {
            stopAdminScanner();
            toggleModal('admin-scanner-overlay', false);
        });
    }
    
    const adminCaptureBtn = document.getElementById('btn-admin-scanner-capture');
    if (adminCaptureBtn) adminCaptureBtn.addEventListener('click', captureAdminPhoto);
}

async function adminLogout() {
    await fetch('../../backend/api/admin.php?action=logout').catch(() => {});
    showAdminLogin('Je bent uitgelogd.');
}

// ================================================================
// DATA LADEN
// ================================================================

async function loadDashboard() {
    console.group('Admin Dashboard: loadDashboard');
    showTableLoading();
    try {
        const response = await fetch('../../backend/api/admin.php?action=get-lots');
        const data = await response.json();
        
        console.log('[Dashboard] Data received:', data);

        if (data.success) {
            allLots = data.lots;
            updateStats(data.stats);
            applyFilters(); // Renders de tabel
        } else {
            console.error('[Dashboard] API Error:', data.error);
            showToast('Fout bij laden data: ' + data.error, 'error');
            document.getElementById('lot-tbody').innerHTML = `<tr><td colspan="11" class="table-empty">Error: ${data.error}</td></tr>`;
        }
    } catch (error) {
        console.error('[Dashboard] Fetch error:', error);
        showToast('Netwerkfout bij laden', 'error');
        document.getElementById('lot-tbody').innerHTML = `<tr><td colspan="11" class="table-empty">Netwerkfout bij laden.</td></tr>`;
    } finally {
        console.groupEnd();
    }
}

function updateStats(serverStats = null) {
    if (!allLots) return;
    
    const maxDev = parseFloat(document.getElementById('param-max-dev')?.value || 25);
    
    const stats = {
        total: allLots.length,
        magic_sent: allLots.filter(l => l.magic_link_status === 'valid').length,
        awaiting: allLots.filter(l => l.magic_link_status === 'valid' && !l.reading_id).length,
        action: allLots.filter(l => l.reading_status === 'pending').length,
        afwijkingen: allLots.filter(l => checkLotAbnormality(l, maxDev)).length,
        approved: allLots.filter(l => l.reading_status === 'approved').length
    };

    document.getElementById('stat-total-lots').textContent = stats.total || 0;
    document.getElementById('stat-magic-sent').textContent = stats.magic_sent || 0;
    document.getElementById('stat-awaiting').textContent   = stats.awaiting || 0;
    document.getElementById('stat-action').textContent     = stats.action || 0;
    document.getElementById('stat-afwijkingen').textContent= stats.afwijkingen || 0;
    document.getElementById('stat-approved').textContent   = stats.approved || 0;
    if (serverStats) {
        if (serverStats.avg_gas !== undefined) document.getElementById('stat-avg-gas').textContent = serverStats.avg_gas;
        if (serverStats.avg_water !== undefined) document.getElementById('stat-avg-water').textContent = serverStats.avg_water;
        if (serverStats.avg_elec !== undefined) document.getElementById('stat-avg-elec').textContent = serverStats.avg_elec;
    }
}

// ================================================================
// TABEL RENDEREN & FILTEREN
// ================================================================

function showTableLoading() {
    document.getElementById('lot-tbody').innerHTML = `<tr><td colspan="11" class="table-loading">Data laden...</td></tr>`;
}

function applyFilters() {
    const searchInput = document.getElementById('search-lot');
    const search = searchInput ? searchInput.value.toLowerCase() : '';
    
    const typeRadio = document.querySelector('input[name="filter-type"]:checked');
    const type = typeRadio ? typeRadio.value : 'all';
    
    const activeFilterBtn = document.querySelector('.filter-card.active');
    const filterData = activeFilterBtn ? activeFilterBtn.dataset.filter : 'all';

    const filtered = allLots.filter(lot => {
        const nrStr = String(lot.lot_number);
        const searchTerms = search.split(' ');
        
        // Match op nummer, naam of e-mail
        const matchSearch = searchTerms.every(term => 
            nrStr.includes(term) || 
            (lot.user_name && lot.user_name.toLowerCase().includes(term)) ||
            (lot.user_email && lot.user_email.toLowerCase().includes(term))
        );

        const matchType = (type === 'all') || (lot.lot_type === type);

        const maxDev = parseFloat(document.getElementById('param-max-dev')?.value || 25);
        const hasLiveAfwijking = checkLotAbnormality(lot, maxDev);

        let matchStatus = true;
        if (filterData === 'pending') matchStatus = (lot.reading_status === 'pending');
        else if (filterData === 'wacht') matchStatus = (lot.magic_link_status === 'valid' && !lot.reading_id);
        else if (filterData === 'approved') matchStatus = (lot.reading_status === 'approved');
        else if (filterData === 'afwijking') matchStatus = hasLiveAfwijking;
        else if (filterData === 'verstuurd') matchStatus = (lot.magic_link_status === 'valid');
        else if (filterData === 'actie') matchStatus = (lot.reading_status === 'pending');
        else if (filterData === 'betaalstatus') matchStatus = false;

        return matchSearch && matchType && matchStatus;
    });

    renderTable(filtered);
    updateStats(); // Recalculate stats based on current maxDev and allLots

    // Update teller
    document.getElementById('result-count').textContent = `Toont ${filtered.length} resultaten`;
}


function exportSepa() {
    if (selectedLotIds.size === 0) return;
    const ids = Array.from(selectedLotIds).join(',');
    window.location.href = `../../backend/api/admin.php?action=export-sepa&lot_ids=${ids}`;
}

function renderTable(lots) {
    const tbody = document.getElementById('lot-tbody');
    tbody.innerHTML = '';
    
    if (lots.length === 0) {
        tbody.innerHTML = `<tr><td colspan="11" class="table-empty">Geen kavels gevonden met deze filters.</td></tr>`;
        return;
    }

    const maxDev = parseFloat(document.getElementById('param-max-dev')?.value || 25);
    
    lots.forEach(lot => {
        const hasLiveAfwijking = checkLotAbnormality(lot, maxDev);
        const tr = document.createElement('tr');
        if (hasLiveAfwijking) tr.classList.add('row-afwijking');
        if (lot.is_resident_active == 0) tr.classList.add('row-inactive');

        const userEmailAttr = lot.user_email ? `title="${lot.user_email}"` : 'title="Geen email gekoppeld"';

        
        // Kavel & Type
        const typeClass = lot.lot_type === 'bebouwd' ? 'chip-bebouwd' : 'chip-onbebouwd';
        
        // Magic Link (pardon, Uitnodigingmail)
        let mlBadge = '';
        if (lot.magic_link_status === 'valid') mlBadge = '<span class="badge badge-ml-valid">✓ Verzonden</span>';
        else if (lot.magic_link_status === 'expired') mlBadge = '<span class="badge badge-ml-expired">⌚ Verlopen</span>';
        else mlBadge = '<span class="badge badge-ml-none">✕ Niet verzonden</span>';

        // Status Badge (Gecombineerd met afwijking)
        let rdBadge = '';
        if (lot.is_resident_active == 0 || !lot.is_resident_active) {
            rdBadge = '<span class="badge" style="background:rgba(239, 68, 68, 0.2); color:#f87171; border:1px solid #f87171;">🏠 Vrijgekomen</span>';
        } else if (lot.reading_status === 'pending') {
            rdBadge = '<span class="badge badge-pending">Wacht op controle</span>';
        } else if (lot.reading_status === 'approved') {
            rdBadge = '<span class="badge badge-ok">✓ Goedgekeurd</span>';
        } else if (lot.reading_status === 'rejected') {
            rdBadge = '<span class="badge badge-error">✕ Afgekeurd</span>';
        } else {
            rdBadge = '<span class="badge" style="background:rgba(255,255,255,0.05); color:#666; font-style:italic;">Geen meting</span>';
        }

        // Betaling
        let paymentBadge = '<span class="badge" style="background:rgba(255,255,255,0.05); color:#666;">-</span>';
        if (lot.payment_status === 'pending') paymentBadge = '<span class="badge" style="background:rgba(245, 158, 11, 0.2); color:#fbbf24; border:1px solid #fbbf24;">Openstaand</span>';
        if (lot.payment_status === 'paid') paymentBadge = '<span class="badge" style="background:rgba(16, 185, 129, 0.2); color:#34d399; border:1px solid #34d399;">Betaald</span>';
        
        // Incasso indicator (mandate)
        let mandateIcon = '';
        if (lot.incasso_mandate_date) mandateIcon = ' <span title="Machtiging aanwezig (Opgeslagen op ' + lot.incasso_mandate_date.substring(0,10) + ')" style="font-size:0.9rem;">🏦✅</span>';
        else if (lot.allow_direct_debit == false || lot.allow_direct_debit == 0) mandateIcon = ' <span title="Incasso uitgeschakeld" style="opacity:0.3; font-size:0.9rem;">🏦🚫</span>';
        else mandateIcon = ' <span title="Geen machtiging" style="opacity:0.3; font-size:0.9rem;">🏦⏳</span>';


        // Acties kolom
        let actionButtons = '';
        
        if (lot.is_resident_active == 0) {
            actionButtons += `
                <button class="action-btn action-approve" style="background:var(--primary); color:white; border-radius:6px; padding:0.4rem 0.8rem; height:auto; width:auto; font-size:0.75rem;" onclick="event.stopPropagation(); openNewResidentModal(${lot.id})">
                    ➕ Nieuwe bewoner
                </button>
            `;
        } else {
            // 1. Goedkeur/Afwijking knop (alleen bij pending)
            if (lot.reading_status === 'pending') {
                if (hasLiveAfwijking) {
                    actionButtons += `
                        <button class="action-btn action-alarm" title="AFWIJKING GECONSTATEERD! Klik voor details" onclick="event.stopPropagation(); viewHistory(${lot.id})">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:1.2em; height:1.2em;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </button>
                    `;
                } else {
                    actionButtons += `
                        <button class="action-btn action-approve" title="Alle metingen van kavel #${lot.lot_number} goedkeuren" onclick="event.stopPropagation(); approveAllReadings(${lot.id})">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:1.2em; height:1.2em;"><path d="M20 6L9 17l-5-5"/></svg>
                        </button>
                    `;
                }
            }

            // 2. Details knop (altijd)
            actionButtons += `
                <button class="action-btn btn-detail" title="Details & Historie" onclick="event.stopPropagation(); viewHistory(${lot.id})">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            `;
        }

        // Verbruik Helper (Synchronized with Detail Modal)
        const renderConsumption = (newReadStr, baselineStr, prevStartStr, unit) => {
            const val = parseFloat(newReadStr);
            const baseline = parseFloat(baselineStr);
            const prevStart = parseFloat(prevStartStr);
            
            if (isNaN(val) || isNaN(baseline)) return '-';
            
            const verbruik = Math.abs(val - baseline);
            const prevVerbruik = Math.abs(baseline - prevStart);
            
            let devStr = '';
            if (prevVerbruik > 0) {
                const maxDev = parseFloat(document.getElementById('param-max-dev')?.value || 25);
                const devPerc = ((verbruik - prevVerbruik) / prevVerbruik) * 100;
                // Groen bij daling of lichte stijging (binnen maxDev), rood bij forse stijging
                const isOk = Math.abs(devPerc) <= maxDev;
                const color = isOk ? '#10b981' : '#ef4444';
                const sign = devPerc > 0 ? '+' : '';
                devStr = ` <span class="consumption-perc" style="color:${color}; font-weight:700;">${sign}${devPerc.toFixed(1)}%</span>`;
            }

            const valSign = verbruik > 0 ? '+' : '';
            const formattedVerbruik = verbruik.toFixed(unit === 'kWh' ? 0 : 1);
            
            return `
                <div class="consumption-box">
                    <div class="consumption-main">
                        <strong>${valSign}${formattedVerbruik}</strong>${devStr}
                    </div>
                    <div class="consumption-sub">
                        ${newReadStr}
                    </div>
                </div>
            `;
        };

        tr.onclick = () => viewHistory(lot.id); // Maak de hele rij klikbaar
        tr.style.cursor = 'pointer';

        tr.innerHTML = `
            <td class="col-kavel">
                <span class="lot-number" ${userEmailAttr}>#${lot.lot_number}</span>
            </td>
            <td class="col-type"><span class="lot-type-chip ${typeClass}">${lot.lot_type}</span></td>
            <td class="col-magic">${mlBadge}</td>
            <td class="col-stand">${rdBadge}</td>
            <td class="col-gas consumption-cell">${renderConsumption(lot.curr_gas_reading, lot.baseline_gas, lot.prev_year_start_gas, 'm³')}</td>
            <td class="col-water consumption-cell">${renderConsumption(lot.curr_water_reading, lot.baseline_water, lot.prev_year_start_water, 'm³')}</td>
            <td class="col-elec consumption-cell">${renderConsumption(lot.curr_elec_reading, lot.baseline_elec, lot.prev_year_start_elec, 'kWh')}</td>
            <td class="col-betaling">${paymentBadge}</td>
            <td class="col-acties">
                <div class="row-actions">
                    ${actionButtons}
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });
}


/**
 * Helper om live te berekenen of een kavel een afwijking heeft op basis van maxDev %
 */
function checkLotAbnormality(lot, maxDev) {
    const meters = [
        { val: lot.curr_gas_reading, base: lot.baseline_gas, prev: lot.prev_year_start_gas },
        { val: lot.curr_water_reading, base: lot.baseline_water, prev: lot.prev_year_start_water },
        { val: lot.curr_elec_reading, base: lot.baseline_elec, prev: lot.prev_year_start_elec }
    ];

    for (const m of meters) {
        const val = parseFloat(m.val);
        const baseline = parseFloat(m.base);
        const prevStart = parseFloat(m.prev);
        
        if (isNaN(val) || isNaN(baseline)) continue;

        const verbruik = Math.abs(val - baseline);
        const prevVerbruik = Math.abs(baseline - prevStart);

        if (prevVerbruik > 0) {
            const devPerc = ((verbruik - prevVerbruik) / prevVerbruik) * 100;
            if (Math.abs(devPerc) > maxDev) return true;
        }
    }
    
    // Fallback naar database vlag als er geen historische data is voor live check 
    // (bijv. eerste jaar), maar de backend heeft wel iets gevonden (bijv. stand lager dan vorige)
    return lot.is_afwijking == 1;
}


// ================================================================
// MAGIC LINKS ACTIES
// ================================================================

async function sendSingleLink(lotId, scenario = 'jaarafrekening') {
        const lot = allLots.find(l => l.id == lotId);
    if (!lot) {
        console.error('[sendSingleLink] ❌ Kavel niet gevonden! lotId:', lotId, '\nIDs:', allLots.map(l => l.id));
        showToast('Fout: kavel niet gevonden. Herlaad de pagina.', 'error');
        return;
    }
    
    let confirmTitle = 'Uitnodiging versturen';
    let confirmMsg = `Weet je zeker dat je een uitnodigingsmail wilt sturen naar Kavel #${lot.lot_number} (${lot.user_email || 'geen mail'})?`;
    
    if (scenario === 'verhuizing') {
        confirmTitle = '⚠️ Verhuizing: Mail Versturen';
        confirmMsg = `LET OP: Hiermee stuur je een verhuis-uitnodiging. De huidige bewoner wordt direct op INACTIEF gezet na het versturen. Doorgaan?`;
    }

    const bodyData = { lot_ids: [lotId], scenario: scenario };
    console.log('[sendSingleLink] Sending request:', bodyData);

        confirmDialog(confirmTitle, confirmMsg, async () => {
                console.error('[sendSingleLink] ✅ Bevestigen geklikt, fetch starten...');
        try {
            const response = await fetch('../../backend/api/admin.php?action=send-magic-link-selected', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(bodyData)
            });
            
            
            if (!response.ok) {
                const text = await response.text();
                console.error('[sendSingleLink] ❌ Non-OK response:', response.status, text.substring(0, 500));
                throw new Error(`Server fout (${response.status}): ${text.substring(0, 100)}`);
            }

            const rawText = await response.text();
            
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (jsonErr) {
                console.error('[sendSingleLink] ❌ JSON parse mislukt. Raw response:', rawText.substring(0, 500));
                throw new Error('Server stuurde geen geldig JSON: ' + rawText.substring(0, 100));
            }

            
            if (data.success) {
                const res = data.results;
                const linkInfo = res.links[0] || {};
                console.error(`[sendSingleLink] ✅ Sent: ${res.sent}, Mails: ${res.mails_sent}, Failed: ${res.failed}`);

                if (linkInfo.mail_skipped) {
                    showToast(`Kavel #${lot.lot_number} op inactief gezet. Mail overgeslagen (er zijn al metingen).`, 'info');
                } else if (!linkInfo.mail_sent) {
                    showToast(`⚠️ Token aangemaakt maar mail NIET verstuurd naar ${lot.user_email || 'bewoner'}. Controleer SMTP-instellingen.`, 'warning');
                    console.error('[sendSingleLink] ⚠️ Mail mislukt. linkInfo:', linkInfo);
                } else {
                    showToast(`✅ Uitnodigingsmail (${scenario}) verstuurd naar ${lot.user_email || 'bewoner'}`, 'success');
                }

                if (scenario === 'verhuizing') {
                    if (document.getElementById('modal-overlay').style.display === 'flex') closeModal();
                }
                loadDashboard();
            } else {
                console.error('[sendSingleLink] ❌ API success:false. Error:', data.error);
                throw new Error(data.error);
            }
        } catch (e) {
            console.error('[sendSingleLink] ❌ EXCEPTION:', e.message, e);
            showToast('Fout bij versturen: ' + e.message, 'error');
        }

    });
}

async function sendMagicLinkAll() {
    // In development: vraag of testmodus gewenst is (stuurt alleen naar testkavels met admin-email)
    const testMode = confirm(
        '🧪 TESTMODUS?\n\n' +
        'Klik OK voor TESTMODUS: verstuurt alleen naar kavels met jouw eigen e-mailadres (veilig).\n\n' +
        'Klik Annuleren voor ECHTE BULK: verstuurt naar ALLE bewoners.'
    );

    const url = '../../backend/api/admin.php?action=send-magic-link-all' + (testMode ? '&test_mode=1' : '');
    const label = testMode ? '🧪 TEST-bulk' : '🚀 ECHTE bulk';

    confirmDialog(
        `${label}: Uitnodigingen versturen`, 
        testMode
            ? `Testmodus: verstuurt alleen naar kavels met jouw eigen e-mailadres. Vorige testlinks komen te vervallen. Doorgaan?`
            : `Waarschuwing: Hiermee verstuur je een uitnodigingsmail naar ALLE kavels met een bewoner. Vorige links komen te vervallen. Doorgaan?`,
        async () => {
            showToast(`${label} — e-mails aan het versturen...`, 'info');
            try {
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    const res = data.results;
                    const listText = res.links.map(l => `Kavel ${l.lot_number}: ${l.link}`).join('\n');
                    const isCopied = copyToClipboard(listText);
                    const notSent = res.sent - res.mails_sent;
                    const prefix = testMode ? '🧪 Test: ' : '';

                    if (res.sent === 0) {
                        showToast(`${prefix}Geen kavels gevonden met jouw e-mailadres. Zorg dat kavel 0 het admin-mailadres heeft.`, 'warning');
                    } else if (res.mails_sent === res.sent) {
                        showToast(`${prefix}✅ ${res.sent} uitnodigingsmails verstuurd${isCopied ? ' en gekopieerd' : ''}!`, 'success');
                    } else if (res.mails_sent === 0) {
                        showToast(`${prefix}⚠️ Tokens aangemaakt maar GEEN mails verstuurd (${res.sent}). Controleer SMTP!`, 'warning');
                        console.error('[sendMagicLinkAll] Alle mails mislukt:', res.links);
                    } else {
                        showToast(`${prefix}✅ ${res.mails_sent} verstuurd, ⚠️ ${notSent} mislukt.`, 'warning');
                    }
                    console.log('[sendMagicLinkAll] Links:', res.links);
                    
                    await loadDashboard(); 
                } else throw new Error(data.error);
            } catch (err) {
                console.error('[sendMagicLinkAll] Fout:', err);
                showToast(err.message, 'error');
            }
        }
    );
}


// Afwijkingen panel logica is verwijderd. Gebruik weergave via tabel filters.

// ================================================================
// DETAIL MODALS & HISTORIE
// ================================================================

async function viewHistory(lotId, resetBatch = true, occupancyId = null) {
    if (resetBatch) isInBatchMode = false; // Alleen resetten als we handmatig kijken
    currentLotId = lotId;
    currentOccupancyId = occupancyId; // Kan null zijn voor 'standaard' view (actieve bewoner)
    console.group(`viewHistory Kavel #${lotId}`);
    try {
        toggleModal('modal-overlay', true);
        const content = document.getElementById('modal-history-content');
        document.getElementById('modal-title').textContent = 'Laden...';
        document.getElementById('modal-subtitle').textContent = '';
        content.innerHTML = '<div class="loading-state">Data laden...</div>';
        
        switchModalTab('tab-history');

        console.log('[viewHistory] Requesting data for lot', lotId);
        const response = await fetch('../../backend/api/admin.php?action=get-history&lot_id=' + lotId);
        
        if (!response.ok) {
            throw new Error(`Server response error: ${response.status} ${response.statusText}`);
        }

        const data = await response.json();
        console.log('[viewHistory] Data received:', data);
        
        if (data.success) {
            const currentLot = allLots.find(l => l.id == lotId) || {};
            const baseline = data.history[0] || {};
            const prevGas = parseFloat(baseline.gas_new_reading) || 0;
            const prevWater = parseFloat(baseline.water_new_reading) || 0;
            const prevElec = parseFloat(baseline.electricity_new_reading) || 0;
            
            document.getElementById('modal-title').textContent = `Kavel #${data.lot.lot_number}`;
            console.log('[viewHistory] Lot data:', data.lot);
                    
            // Bewoner sectie bepalen
            let residentHtml = '';
            const isActive = data.lot.is_resident_active == 1;

            if (isActive && data.lot.resident_email) {
                residentHtml = `
                    <div class="resident-info-card">
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; margin-bottom: 0.25rem;">Actieve Bewoner</div>
                            <div style="font-size: 1rem; font-weight: 700; color: #1e293b;">${data.lot.resident_name || 'Naam onbekend'}</div>
                            <div style="font-size: 0.85rem; color: #64748b;">${data.lot.resident_email}</div>
                            ${data.lot.resident_since_date ? `<div style="font-size: 0.75rem; color: #94a3b8; margin-top:0.25rem;">Sinds: ${data.lot.resident_since_date}</div>` : ''}
                        </div>
                        <button class="btn btn-ghost btn-xs" style="color: #3b82f6; width: auto; padding: 0.35rem 0.75rem;" onclick="openEditResidentModal(${lotId}, '${data.lot.resident_name || ''}', '${data.lot.resident_email || ''}')">
                            ✏️ Wijzig
                        </button>
                    </div>
                `;
            } else {
                residentHtml = `
                    <div style="border: 1px dashed rgba(239, 68, 68, 0.3); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; text-align: center; background: rgba(239, 68, 68, 0.02);">
                        <p style="font-size: 0.85rem; color: #ef4444; margin-bottom: 1rem; font-weight:600;">⚠️ Kavel is momenteel vrijgekomen (inactief).</p>
                        <button class="btn btn-primary btn-sm" style="width: auto;" onclick="openNewResidentModal(${lotId})">
                            ➕ Nieuwe Bewoner Toevoegen
                        </button>
                    </div>
                `;
            }

            document.getElementById('modal-subtitle').innerHTML = `
                <div style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                    ${data.lot.lot_type.toUpperCase()} • ${data.lot.address || 'Geen adres ingevuld'}
                </div>
                ${residentHtml}
                
                ${isActive ? `
                <div style="margin-top: 1.5rem;">
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; margin-bottom: 0.75rem;">Standen opnemen</div>
                    <div class="measurement-row" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                        <button class="btn btn-primary btn-sm btn-flex" style="width: auto; padding: 0.4rem 0.8rem; font-size: 0.8rem;" onclick="closeModal(); openManualInput(${lotId})">
                            <span class="btn-icon">✍️</span> <span class="long-label">Zelf doen</span><span class="short-label">Zelf</span>
                        </button>
                        <button class="btn btn-primary btn-sm btn-flex" style="width: auto; padding: 0.4rem 0.8rem; font-size: 0.8rem;" onclick="sendSingleLink(${lotId}, 'jaarafrekening')" ${data.lot.resident_email ? '' : 'disabled title="Geen e-mailadres gekoppeld aan deze bewoner"'}>
                            <span class="btn-icon">✉️</span> <span class="long-label">Stuur mailverzoek</span><span class="short-label">Mail</span>
                        </button>
                        <button class="btn btn-secondary btn-sm btn-flex" style="width: auto; padding: 0.4rem 0.8rem; font-size: 0.8rem; background:#f59e0b; border-color:#d97706; color:#fff;" onclick="sendSingleLink(${lotId}, 'verhuizing')" ${data.lot.resident_email ? '' : 'disabled title="Geen e-mailadres gekoppeld aan deze bewoner"'}>
                            <span class="btn-icon">📦</span> <span class="long-label">Verhuizing starten</span><span class="short-label">Verhuizing</span>
                        </button>
                    </div>
                </div>
                ` : ''}
            `;
            
            let html = `
                <div class="section-title" style="display: flex; justify-content: space-between; align-items: center;">
                    Nieuwe Metingen (2026)
                    ${data.readings.filter(r => r.status === 'pending').length > 1 ? `
                        <button class="btn btn-xs btn-success" onclick="approveAllReadings(${lotId})" style="padding: 0.2rem 0.6rem; font-size: 0.75rem;">
                            ✅ Keur alle openstaande goed
                        </button>
                    ` : ''}
                </div>

                ${(() => {
                    const r = data.readings[0]; // Laatste meting
                    if (!r) return '';
                    
                    const maxDev = parseFloat(document.getElementById('param-max-dev')?.value || 25);
                    const maxTime = parseFloat(document.getElementById('param-max-time')?.value || 15);
                    
                    const pastPeriods = data.history.filter(h => h.gas_prev_reading !== null && String(h.gas_prev_reading).trim() !== "");
                    const lY = pastPeriods[0];
                    if (!lY) return '';

                    const findings = [];
                    const devData = [];

                    // 1. Verbruik afwijkingen berekenen
                    const checkDev = (curr, base, start, label) => {
                        const val = parseFloat(curr);
                        const baseline = parseFloat(base);
                        const prevStart = parseFloat(start);
                        if (isNaN(val) || isNaN(baseline) || isNaN(prevStart)) return null;

                        const verbruik = Math.abs(val - baseline);
                        const prevVerbruik = Math.abs(baseline - prevStart);
                        if (prevVerbruik <= 0) return null;

                        const perc = ((verbruik - prevVerbruik) / prevVerbruik) * 100;
                        return { label, perc };
                    };

                    const gDev = checkDev(r.gas_new_reading, lY.gas_new_reading, lY.gas_prev_reading, 'Gas');
                    const wDev = checkDev(r.water_new_reading, lY.water_new_reading, lY.water_prev_reading, 'Water');
                    const eDev = checkDev(r.electricity_new_reading, lY.electricity_new_reading, lY.electricity_prev_reading, 'Electra');

                    [gDev, wDev, eDev].forEach(d => {
                        if (d && Math.abs(d.perc) > maxDev) {
                            findings.push(`${d.label} verbruik wijkt ${d.perc > 0 ? '+' : ''}${d.perc.toFixed(1)}% af.`);
                            devData.push(d);
                        }
                    });

                    // 2. Trend Analyse
                    if (devData.length > 1) {
                        const signs = new Set(devData.map(d => Math.sign(d.perc)));
                        if (signs.size > 1) {
                            findings.push(`<strong>Inconsistent verloop</strong>: Sommige meters stijgen terwijl anderen dalen.`);
                        } else {
                            const trend = Array.from(signs)[0] > 0 ? 'stijging' : 'daling';
                            findings.push(`Gezamenlijke ${trend} geconstateerd over meerdere meters.`);
                        }
                    }

                    // 3. Foto Metadata Controle
                    if (r.image_url && r.exif_timestamp) {
                        const uploadTime = new Date(r.created_at).getTime();
                        const photoTime = new Date(r.exif_timestamp).getTime();
                        if (!isNaN(uploadTime) && !isNaN(photoTime)) {
                            const diffMin = Math.abs(uploadTime - photoTime) / 60000;
                            if (diffMin > maxTime) {
                                findings.push(`<strong>Tijdstempel alert</strong>: Foto is ${Math.round(diffMin)} min vóór upload genomen (limiet: ${maxTime} min).`);
                            }
                        }
                    }

                    if (findings.length > 0 || r.is_afwijking == 1) {
                        return `
                        <div class="abnormality-alert">
                            <div class="alert-icon">⚠️</div>
                            <div class="alert-content">
                                <strong>Afwijkingen geconstateerd</strong>
                                <ul style="margin: 0.5rem 0 0 1.2rem; padding: 0; font-size: 0.85rem; color: #7f1d1d;">
                                    ${findings.length > 0 
                                        ? findings.map(f => `<li>${f}</li>`).join('') 
                                        : `<li>${r.afwijking_reden || 'Handmatige markering als afwijking.'}</li>`}
                                </ul>
                            </div>
                        </div>
                        `;
                    }
                    return '';
                })()}

                ${data.readings.length === 0 ? '<div class="table-empty" style="padding:1rem">Nog geen nieuwe metingen ingediend.</div>' : `
                    <table class="data-table" style="margin-bottom:2rem;">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th class="col-gas text-right">Gas (m³)</th>
                                <th class="col-water text-right">Water (m³)</th>
                                <th class="col-elec text-right">Elek (kWh)</th>
                                <th>Foto</th>
                                <th>Status</th>
                                <th class="text-center">Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${(() => {
                                const maxDev = parseFloat(document.getElementById('param-max-dev')?.value || 25);
                                const pastPeriods = data.history.filter(h => h.gas_prev_reading !== null && String(h.gas_prev_reading).trim() !== "");
                                const lY = pastPeriods[0];
                                
                                const vOrigGas = lY ? Math.abs(parseFloat(lY.gas_new_reading) - parseFloat(lY.gas_prev_reading)) : 0;
                                const vOrigWater = lY ? Math.abs(parseFloat(lY.water_new_reading) - parseFloat(lY.water_prev_reading)) : 0;
                                const vOrigElec = lY ? Math.abs(parseFloat(lY.electricity_new_reading) - parseFloat(lY.electricity_prev_reading)) : 0;
                                
                                const renderCell = (newReadStr, prevStand, prevVerbruik, unit) => {
                                    const val = parseFloat(newReadStr);
                                    if (isNaN(val)) return ' - ';
                                    
                                    let devStr = '';
                                    let verbruikStr = '';
                                    
                                    if (prevStand > 0) {
                                        const verbruik = Math.abs(val - prevStand);
                                        verbruikStr = `+${verbruik.toFixed(unit === 'kWh' ? 0 : 1)}`;
                                        
                                        if (prevVerbruik > 0) {
                                            const devPerc = ((verbruik - prevVerbruik) / prevVerbruik) * 100;
                                            const isOk = Math.abs(devPerc) <= maxDev;
                                            const color = isOk ? '#4ade80' : '#f87171';
                                            const sign = devPerc > 0 ? '+' : '';
                                            devStr = `<span style="color:${color}; font-size:0.85em; font-weight:600;">${sign}${devPerc.toFixed(1)}%</span>`;
                                        }
                                    }
                                    return `
                                        <div class="reading-grid">
                                            <div class="rg-val"><strong>${verbruikStr || '-'}</strong></div>
                                            <div class="rg-dev">${devStr}</div>
                                            <div class="rg-abs"><small style="color:var(--text-muted)">${newReadStr}</small></div>
                                        </div>
                                    `;
                                };

                                // Filter: toon alleen metingen van de actieve bewoner
                                const activeOccId = data.occupancy?.find(o => o.is_active == 1)?.id;
                                const filteredReadings = activeOccId 
                                    ? data.readings.filter(r => r.occupancy_id == activeOccId)
                                    : data.readings;

                                return filteredReadings.map(r => {
                                    const photoHtml = r.image_url 
                                        ? `<a href="../uploads/${r.image_url}" target="_blank" class="photo-link">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                            Bekijk
                                          </a>` 
                                        : '<span class="no-photo">Geen</span>';
                                    
                                    let statusNL = r.status;
                                    if (r.status === 'pending') statusNL = 'Wacht op controle';
                                    if (r.status === 'approved') statusNL = 'Goedgekeurd';
                                    if (r.status === 'rejected') statusNL = 'Afgekeurd';

                                    return `
                                        <tr>
                                            <td data-label="Datum">${r.reading_date}</td>
                                            <td class="text-right" data-label="Gas">${renderCell(r.gas_new_reading, prevGas, vOrigGas, 'm³')}</td>
                                            <td class="text-right" data-label="Water">${renderCell(r.water_new_reading, prevWater, vOrigWater, 'm³')}</td>
                                            <td class="text-right" data-label="Elek">${renderCell(r.electricity_new_reading, prevElec, vOrigElec, 'kWh')}</td>
                                            <td data-label="Foto">${photoHtml}</td>
                                            <td data-label="Status">
                                                <span class="badge badge-reading-${r.status}">
                                                    ${statusNL}
                                                </span>
                                            </td>
                                            <td class="text-center" data-label="Acties">
                                                <div class="row-actions" style="justify-content: flex-end;">
                                                    ${r.status === 'pending' ? `
                                                        <button class="btn btn-xs btn-success" title="Goedkeuren" onclick="approveReading(${r.id}, ${lotId})">✅</button>
                                                        <button class="btn btn-xs btn-error" title="Afwijzen" onclick="rejectReading(${r.id}, ${lotId})">❌</button>
                                                    ` : `
                                                        <button class="btn btn-xs btn-ghost" title="Heropenen (terug naar wacht)" onclick="resetReading(${r.id}, ${lotId})">↺</button>
                                                    `}
                                                </div>
                                            </td>
                                        </tr>
                                    `;
                                }).join('');
                            })()}
                        </tbody>
                    </table>
                `}

                <div class="section-title">Bewonershistorie</div>
                ${(() => {
                    if (!data.occupancy || data.occupancy.length === 0) return '<div class="table-empty" style="padding:1rem">Geen bewonershistorie gevonden.</div>';
                    
                    const occRows = data.occupancy.map(o => {
                        const isActive = o.is_active == 1;
                        const periodStr = `${o.start_date} t/m ${o.end_date || 'heden'}`;
                        const isBilled = data.readings.some(r => r.occupancy_id == o.id && r.status === 'approved');

                        return `
                            <tr style="${isActive ? 'background: rgba(59, 130, 246, 0.05);' : ''}">
                                <td data-label="Periode">
                                    <strong>${periodStr}</strong><br>
                                    <small style="color:var(--text-muted)">${o.resident_name}</small>
                                </td>
                                <td class="text-right" data-label="Startstanden">
                                    <small>G: ${o.start_gas || 0}<br>W: ${o.start_water || 0}<br>E: ${o.start_elec || 0}</small>
                                </td>
                                <td class="text-right" data-label="Eindstanden">
                                    ${(() => {
                                        // Voorwaardestoetsen voor eindstanden
                                        if (o.end_gas !== null && o.end_gas !== "" && o.is_active == 0) {
                                            return `<small>G: ${o.end_gas}<br>W: ${o.end_water}<br>E: ${o.end_elec}</small>`;
                                        }

                                        // Zoek laatste goedgekeurde stand in de readings voor deze specifieke bewoner
                                        const latestApp = data.readings
                                            .filter(r => r.occupancy_id == o.id && r.status === 'approved')
                                            .sort((a,b) => b.id - a.id)[0];

                                        if (latestApp) {
                                            return `
                                                <div style="font-size:0.75rem; color:#34d399; font-weight:600;">Huidige stand:</div>
                                                <small>G: ${latestApp.gas_new_reading}<br>W: ${latestApp.water_new_reading}<br>E: ${latestApp.electricity_new_reading}</small>
                                            `;
                                        }

                                        if (o.is_active == 1) {
                                            return '<span class="badge" style="background:rgba(52, 211, 153, 0.1); color:#34d399; font-size:0.7rem;">Heden (actief)</span>';
                                        }
                                        
                                        const pending = data.readings.some(r => r.occupancy_id == o.id && r.status === 'pending');
                                        if (pending) {
                                            return '<span class="badge" style="background:rgba(251, 191, 36, 0.1); color:#fbbf24; font-size:0.7rem;">⌛ Wacht op controle</span>';
                                        }
                                        return '<small style="color:var(--text-muted)">Nog niet bekend</small>';
                                    })()}
                                </td>
                                <td class="text-center" data-label="Factuur">
                                    ${isBilled ? `
                                        <button class="btn btn-xs btn-primary" onclick="openOccupancyInvoice(${lotId}, ${o.id})">
                                            📄 Factureer
                                        </button>
                                    ` : '<small style="color:var(--text-muted)">Geen meting</small>'}
                                </td>
                            </tr>
                        `;
                    }).join('');

                    return `
                        <table class="data-table" style="margin-bottom:2rem;">
                            <thead>
                                <tr>
                                    <th>Periode / Bewoner</th>
                                    <th class="text-right">Startstanden</th>
                                    <th class="text-right">Eindstanden</th>
                                    <th class="text-center">Actie</th>
                                </tr>
                            </thead>
                            <tbody>${occRows}</tbody>
                        </table>
                    `;
                })()}

                <div class="section-title">Alle Metingen</div>
                ${(() => {
                    const pastPeriods = data.history.filter(h => h.gas_prev_reading !== null && String(h.gas_prev_reading).trim() !== "");
                    if (pastPeriods.length === 0) return '<div class="table-empty" style="padding:1rem">Geen import historie gevonden.</div>';
                    // ... rest of meters history ...
                    const maxDev = parseFloat(document.getElementById('param-max-dev')?.value || 25);
                    const rows = pastPeriods.map((h, i) => {
                        const gV = Math.abs(parseFloat(h.gas_new_reading) - parseFloat(h.gas_prev_reading));
                        const wV = Math.abs(parseFloat(h.water_new_reading) - parseFloat(h.water_prev_reading));
                        const eV = Math.abs(parseFloat(h.electricity_new_reading) - parseFloat(h.electricity_prev_reading));
                        
                        const hPrev = pastPeriods[i + 1];
                        const devStr = (v, prevHNew, prevHPrev) => {
                            if (hPrev && parseFloat(prevHNew) > 0) {
                                const prevV = Math.abs(parseFloat(prevHNew) - parseFloat(prevHPrev));
                                if (prevV > 0) {
                                    const devPerc = ((v - prevV) / prevV) * 100;
                                    const isOk = Math.abs(devPerc) <= maxDev;
                                    return ` <span style="color:${isOk ? '#4ade80' : '#f87171'}; font-size:0.85em; font-weight:600;">${devPerc > 0 ? '+' : ''}${devPerc.toFixed(1)}%</span>`;
                                }
                            }
                            return '';
                        };

                        return `
                            <tr>
                                <td data-label="Jaar">${h.period_name.replace('Jaarafrekening ', '')}</td>
                                <td class="text-right" data-label="Gas Verbruik">
                                    <strong>${gV.toFixed(1)} m³</strong>${devStr(gV, hPrev?.gas_new_reading, hPrev?.gas_prev_reading)}<br>
                                    <small style="color:var(--text-muted)">${h.gas_new_reading}</small>
                                </td>
                                <td class="text-right" data-label="Water Verbruik">
                                    <strong>${wV.toFixed(1)} m³</strong>${devStr(wV, hPrev?.water_new_reading, hPrev?.water_prev_reading)}<br>
                                    <small style="color:var(--text-muted)">${h.water_new_reading}</small>
                                </td>
                                <td class="text-right" data-label="Elek Verbruik">
                                    <strong>${eV.toFixed(0)} kWh</strong>${devStr(eV, hPrev?.electricity_new_reading, hPrev?.electricity_prev_reading)}<br>
                                    <small style="color:var(--text-muted)">${h.electricity_new_reading}</small>
                                </td>
                            </tr>
                        `;
                    }).join('');

                    return `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Jaar</th>
                                    <th class="text-right">Gas Verbruik</th>
                                    <th class="text-right">Water Verbruik</th>
                                    <th class="text-right">Elek Verbruik</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rows}
                            </tbody>
                        </table>
                    `;
                })()}
            `;
            content.innerHTML = html;
        } else {
            console.error('[viewHistory] API Error:', data.error);
            content.innerHTML = `<div class="table-empty">Fout: ${data.error}</div>`;
        }
    } catch (e) {
        console.error('[viewHistory] General Error:', e);
        const content = document.getElementById('modal-history-content');
        if (content) {
            content.innerHTML = `<div class="table-empty">Error: ${e.message}</div>`;
        }
    } finally {
        console.groupEnd();
    }
}

function switchModalTab(tabId) {
    console.log('[switchModalTab] Switching to', tabId);
    
    const btns = document.querySelectorAll('.tab-btn');
    const contents = document.querySelectorAll('.tab-content');
    
    btns.forEach(b => b.classList.remove('active'));
    contents.forEach(c => c.classList.remove('active'));
    
    const activeBtn = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
    const activeContent = document.getElementById(tabId);
    
    if (activeBtn) {
        activeBtn.classList.add('active');
    } else {
        console.warn(`[switchModalTab] Tab button not found for: ${tabId}`);
    }
    
    if (activeContent) {
        activeContent.classList.add('active');
    } else {
        console.warn(`[switchModalTab] Tab content not found for ID: ${tabId}`);
    }
}

function closeModal() {
    isInBatchMode = false;
    toggleModal('modal-overlay', false);
}

// ================================================================
// CONFIRMATION DIALOG
// ================================================================

function confirmDialog(title, message, callback) {
        document.getElementById('confirm-title').textContent = title;
    document.getElementById('confirm-message').textContent = message;

    const okBtn = document.getElementById('confirm-ok');
    okBtn.onclick = () => {
                closeConfirm();
        callback();
    };

    const cancelBtn = document.getElementById('confirm-cancel');
    cancelBtn.onclick = () => closeConfirm();

    // Blokkeer clicks op modal-overlay terwijl confirm open is
    document.body.classList.add('confirm-open');
    toggleModal('confirm-overlay', true);
    console.error('[confirmDialog] ✅ Dialog geopend:', title);
}

function closeConfirm() {
    document.body.classList.remove('confirm-open');
    const okBtn = document.getElementById('confirm-ok');
    if (okBtn) okBtn.onclick = null;
    toggleModal('confirm-overlay', false);
}


// ================================================================
// TOASTS
// ================================================================

function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    let icon = 'ℹ️';
    if(type === 'success') icon = '✅';
    if(type === 'error') icon = '❌';

    toast.innerHTML = `<span>${icon}</span> <span>${message}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'toastOut 0.3s ease both';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ================================================================
// REVIEW ACTIES
// ================================================================

async function approveReading(id, lotId) {
    try {
        const response = await fetch(`../../backend/api/admin.php?action=approve-reading&id=${id}`);
        const data = await response.json();
        if (data.success) {
            showToast('Meting goedgekeurd', 'success');
            viewHistory(lotId);
            loadDashboard();
        } else throw new Error(data.error);
    } catch (e) { showToast(e.message, 'error'); }
}

async function approveAllReadings(lotId) {
    try {
        const response = await fetch(`../../backend/api/admin.php?action=approve-all-lot-readings&lot_id=${lotId}`);
        const data = await response.json();
        if (data.success) {
            showToast('Alle openstaande metingen goedgekeurd', 'success');
            if (document.getElementById('modal-overlay').style.display === 'flex') {
                viewHistory(lotId);
            }
            loadDashboard();
        } else throw new Error(data.error);
    } catch (e) { showToast(e.message, 'error'); }
}

async function rejectReading(id, lotId) {
    confirmDialog('Afwijzen', 'Weet je zeker dat je deze meting wilt afwijzen?', async () => {
        try {
            const response = await fetch(`../../backend/api/admin.php?action=reject-reading&id=${id}`);
            const data = await response.json();
            if (data.success) {
                showToast('Meting afgewezen', 'info');
                viewHistory(lotId);
                loadDashboard();
            } else throw new Error(data.error);
        } catch (e) { showToast(e.message, 'error'); }
    });
}

async function resetReading(id, lotId) {
    try {
        const response = await fetch(`../../backend/api/admin.php?action=reset-reading&id=${id}`);
        const data = await response.json();
        if (data.success) {
            showToast('Status heropend (wacht op goedkeuring)', 'info');
            viewHistory(lotId);
            loadDashboard();
        } else throw new Error(data.error);
    } catch (e) { showToast(e.message, 'error'); }
}

// ================================================================
// HANDMATIGE INVOER (PROXY)
// ================================================================

function openManualInput(lotId) {
    const lot = allLots.find(l => l.id == lotId);
    if (!lot) return;

    document.getElementById('manual-lot-id').value = lotId;
    document.getElementById('manual-gas').value = '';
    document.getElementById('manual-water').value = '';
    document.getElementById('manual-elec').value = '';
    document.getElementById('manual-photo').value = '';
    document.getElementById('manual-photo-preview').innerHTML = '';
    
    toggleModal('manual-input-overlay', true);
}

function closeManualModal() {
    toggleModal('manual-input-overlay', false);
}

function handleManualPhotoChange(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('manual-photo-preview');
    if (file) {
        const reader = new FileReader();
        reader.onload = (e) => {
            preview.innerHTML = `<img src="${e.target.result}" style="max-width:100%; border-radius:8px; margin-top:10px;">`;
        };
        reader.readAsDataURL(file);
    } else {
        preview.innerHTML = '';
    }
}

async function submitManualInput(e) {
    e.preventDefault();
    const lotId = document.getElementById('manual-lot-id').value;
    const gas = document.getElementById('manual-gas').value;
    const water = document.getElementById('manual-water').value;
    const elec = document.getElementById('manual-elec').value;
    const photoFile = document.getElementById('manual-photo').files[0];

    if (!gas && !water && !elec) {
        showToast('Vul tenminste één meterstand in', 'error');
        return;
    }

    const btnSubmit = document.getElementById('manual-submit');
    btnSubmit.disabled = true;
    btnSubmit.textContent = 'Bezig met opslaan...';

    try {
        let base64Photo = null;
        if (photoFile) {
            base64Photo = await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.onerror = reject;
                reader.readAsDataURL(photoFile);
            });
        }

        const response = await fetch('../../backend/api/admin.php?action=save-proxy-reading', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                lot_id: lotId,
                gas: gas || null,
                water: water || null,
                elec: elec || null,
                image: base64Photo
            })
        });

        const data = await response.json();
        if (data.success) {
            showToast('Meting succesvol opgeslagen namens bewoner', 'success');
            closeManualModal();
            loadDashboard();
        } else throw new Error(data.error);

    } catch (err) {
        showToast(err.message, 'error');
    } finally {
        btnSubmit.disabled = false;
        btnSubmit.textContent = '💾 Opslaan';
    }
}

// ================================================================
// TARIEVEN & VASTE LASTEN
// ================================================================

async function openTariffsModal() {
    toggleModal('tariffs-overlay', true);
    loadAdmins();
    
    try {
        const response = await fetch('../../backend/api/admin.php?action=get-tariffs');
        const data = await response.json();
        
        if (data.success) {
            const t = data.tariffs;
            const f = data.fixed;
            
            // Tarieven
            document.getElementById('rate-gas').value = t.gas_price_per_m3;
            document.getElementById('rate-water').value = t.water_price_per_m3;
            document.getElementById('rate-elec').value = t.electricity_price_per_kwh;
            
            // Vaste lasten bebouwd
            const beb = f.find(i => i.lot_type === 'bebouwd') || {};
            document.getElementById('fixed-gas-beb').value = beb.vast_gas_per_month || 0;
            document.getElementById('fixed-water-beb').value = beb.vast_water_per_month || 0;
            document.getElementById('fixed-elec-beb').value = beb.vast_electricity_per_month || 0;
            document.getElementById('fixed-vve-beb').value = beb.vve_per_year || 0;
            document.getElementById('fixed-erfpacht-beb').value = beb.erfpacht_per_year || 0;
            
            // Vaste lasten onbebouwd
            const onb = f.find(i => i.lot_type === 'onbebouwd') || {};
            document.getElementById('fixed-vve-onb').value = onb.vve_per_year || 0;
            document.getElementById('fixed-erfpacht-onb').value = onb.erfpacht_per_year || 0;

            // Nieuw: Systeeminstellingen ophalen
            const resSys = await fetch('../../backend/api/admin.php?action=get-system-settings');
            const dataSys = await resSys.json();
            if (dataSys.success) {
                const s = dataSys.settings;
                document.getElementById('sys-park-name').value = s.park_name || '';
                document.getElementById('sys-iban').value = s.sepa_creditor_iban || '';
                document.getElementById('sys-creditor-id').value = s.sepa_creditor_id || '';
                document.getElementById('sys-contact-email').value = s.contact_email || '';
                
                // Afwijking parameters
                if (s.max_deviation) document.getElementById('param-max-dev').value = s.max_deviation;
                if (s.max_time) document.getElementById('param-max-time').value = s.max_time;
            }
            
        } else throw new Error(data.error);
    } catch (e) {
        showToast('Fout bij laden tarieven: ' + e.message, 'error');
    }
}

function closeTariffsModal() {
    toggleModal('tariffs-overlay', false);
}

async function submitTariffs(e) {
    e.preventDefault();
    const btn = document.getElementById('tariffs-submit');
    btn.disabled = true;
    btn.textContent = 'Bezig met opslaan...';

    const payload = {
        tariffs: {
            gas: document.getElementById('rate-gas').value,
            water: document.getElementById('rate-water').value,
            elec: document.getElementById('rate-elec').value
        },
        fixed_bebouwd: {
            gas: document.getElementById('fixed-gas-beb').value,
            water: document.getElementById('fixed-water-beb').value,
            elec: document.getElementById('fixed-elec-beb').value,
            vve: document.getElementById('fixed-vve-beb').value,
            erfpacht: document.getElementById('fixed-erfpacht-beb').value
        },
        fixed_onbebouwd: {
            vve: document.getElementById('fixed-vve-onb').value,
            erfpacht: document.getElementById('fixed-erfpacht-onb').value
        },
        settings: {
            park_name: document.getElementById('sys-park-name').value,
            sepa_creditor_iban: document.getElementById('sys-iban').value,
            sepa_creditor_id: document.getElementById('sys-creditor-id').value,
            contact_email: document.getElementById('sys-contact-email').value,
            sepa_creditor_name: document.getElementById('sys-park-name').value,
            max_deviation: document.getElementById('param-max-dev').value,
            max_time: document.getElementById('param-max-time').value
        }
    };

    try {
        const response = await fetch('../../backend/api/admin.php?action=update-tariffs', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        // Systeeminstellingen apart opslaan of in 1 keer? 
        // Laten we ze in 1 keer opslaan door de API te combineren of 2 calls te doen.
        // Voor eenvoud doen we nu een 2e call.
        await fetch('../../backend/api/admin.php?action=update-system-settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ settings: payload.settings })
        });

        const data = await response.json();
        if (data.success) {
            showToast('Tarieven en systeeminstellingen succesvol bijgewerkt', 'success');
            closeTariffsModal();
        } else throw new Error(data.error);
    } catch (e) {
        showToast(e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = '💾 Wijzigingen Opslaan';
    }
}

async function finalizeYear() {
    if (!confirm('WEET JE DIT ZEKER?\n\nHiermee sluit je het huidige jaar af en start je een nieuw jaar.\n\n- De huidige standen worden vastgelegd als beginstand voor volgend jaar.\n- Dit kan niet ongedaan worden gemaakt.\n- Alle metingen moeten goedgekeurd of afgekeurd zijn.')) {
        return;
    }

    const btn = document.getElementById('btn-finalize-year');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⌛ Bezig met afsluiten...';

    try {
        const response = await fetch('../../backend/api/admin.php?action=finalize-year', {
            method: 'POST'
        });
        const data = await response.json();

        if (data.success) {
            showToast(`✅ Jaar succesvol afgesloten! Nieuw jaar ${data.next_year} is gestart.`, 'success');
            closeTariffsModal();
            loadDashboard(); // Refresh de boel
        } else {
            throw new Error(data.error || 'Onbekende fout bij jaarafsluiting');
        }
    } catch (e) {
        showToast('Fout: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

// ================================================================
// FACTURATIE (INVOICE) LOGICA
// ================================================================

async function openOccupancyInvoice(lotId, occupancyId) {
    currentLotId = lotId;
    currentOccupancyId = occupancyId;
    
    // Zorg dat de modal open is
    if (document.getElementById('modal-overlay').style.display !== 'flex') {
        toggleModal('modal-overlay', true);
    }
    
    // Switch naar factuur tab
    switchModalTab('tab-invoice');
    
    // Laad preview
    loadInvoicePreview(lotId, occupancyId);
}

async function loadInvoicePreview(lotId, occupancyId = null, correction = 0, reason = '') {
    const content = document.getElementById('modal-invoice-content');
    if (!correction) content.innerHTML = '<div class="loading-state">Berekening laden...</div>';
    
    currentLotId = lotId || currentLotId;
    currentOccupancyId = occupancyId || currentOccupancyId;

    try {
        let url = `../../backend/api/admin.php?action=get-billing-preview&correction=${correction}&reason=${encodeURIComponent(reason)}`;
        if (currentOccupancyId) url += `&occupancy_id=${currentOccupancyId}`;
        else if (currentLotId) url += `&lot_id=${currentLotId}`;

        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            currentPreviewData = data.preview;
            renderInvoicePreview(data.preview);
        } else throw new Error(data.error);
    } catch (e) {
        content.innerHTML = `<div class="table-empty">Fout bij berekening: ${e.message}</div>`;
    }
}

function renderInvoicePreview(p) {
    const content = document.getElementById('modal-invoice-content');
    
    let html = `
        <div class="invoice-preview-container">
            <div class="glass" style="margin-bottom:1.5rem; padding:1.25rem; border:1px solid var(--primary); background:rgba(59, 130, 246, 0.05)">
                <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; margin-bottom: 0.5rem;">Factuurontvanger</div>
                <div style="font-size: 1.1rem; font-weight: 700; color: #000;">${p.occupancy.resident_name || 'Bewoner'}</div>
                <div style="font-size: 0.9rem; color: #94a3b8;">${p.occupancy.resident_email}</div>
                <div style="font-size: 0.75rem; color: #64748b; margin-top:0.5rem;">Kavel #${p.lot.lot_number} • Periode: ${p.occupancy.start_date} t/m ${p.occupancy.end_date || 'heden'}</div>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Omschrijving</th>
                        <th class="text-right">Verbruik</th>
                        <th class="text-right">Bedrag (excl. BTW)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Gasverbruik</td>
                        <td class="text-right">${p.consumption.gas} m³</td>
                        <td class="text-right">€ ${p.costs.gas.toFixed(2)}</td>
                    </tr>
                    <tr>
                        <td>Waterverbruik</td>
                        <td class="text-right">${p.consumption.water} m³</td>
                        <td class="text-right">€ ${p.costs.water.toFixed(2)}</td>
                    </tr>
                    <tr>
                        <td>Elektraverbruik</td>
                        <td class="text-right">${p.consumption.elec} kWh</td>
                        <td class="text-right">€ ${p.costs.elec.toFixed(2)}</td>
                    </tr>
                    ${p.consumption.solar > 0 ? `
                    <tr style="color:#4ade80">
                        <td>Teruglevering Zonnepanelen (Credit)</td>
                        <td class="text-right">${p.consumption.solar} kWh</td>
                        <td class="text-right">- € ${p.costs.solar_credit.toFixed(2)}</td>
                    </tr>` : ''}
                    <tr class="section-divider"><td colspan="3"></td></tr>
                    <tr><td>Vaste Lasten Gas (${p.summary.period_months} mnd)</td><td></td><td class="text-right">€ ${p.fixed.gas.toFixed(2)}</td></tr>
                    <tr><td>Vaste Lasten Water (${p.summary.period_months} mnd)</td><td></td><td class="text-right">€ ${p.fixed.water.toFixed(2)}</td></tr>
                    <tr><td>Vaste Lasten Elektra (${p.summary.period_months} mnd)</td><td></td><td class="text-right">€ ${p.fixed.elec.toFixed(2)}</td></tr>
                    <tr><td>VVE Bijdrage</td><td></td><td class="text-right">€ ${p.fixed.vve.toFixed(2)}</td></tr>
                    <tr><td>Erfpacht</td><td></td><td class="text-right">€ ${p.fixed.erfpacht.toFixed(2)}</td></tr>
                    <tr class="section-divider"><td colspan="3"></td></tr>
                </tbody>
            </table>

            <div class="correction-section glass" style="margin-top:1.5rem; padding:1.25rem; border:1px solid rgba(255,255,255,0.05)">
                <h4 style="margin:0 0 1rem 0; font-size:0.9rem;">Handmatige Correctie</h4>
                <div class="input-grid" style="grid-template-columns: 140px 1fr;">
                    <div class="form-group">
                        <label>Bedrag (+ / -)</label>
                        <input type="number" step="0.01" id="inv-correction" class="form-input" value="${p.summary.correction}" onchange="updateInvoiceCorrection()">
                    </div>
                    <div class="form-group">
                        <label>Reden</label>
                        <input type="text" id="inv-reason" class="form-input" value="${p.summary.correction_reason}" placeholder="Bijv. Eenmalige korting" onblur="updateInvoiceCorrection()">
                    </div>
                </div>
            </div>

            <div class="invoice-totals" style="margin-top:2rem; padding:1.5rem; background:rgba(0,0,0,0.2); border-radius:12px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem; color:var(--text-muted)">
                    <span>Subtotaal (excl. BTW)</span>
                    <span>€ ${p.summary.subtotal_ex_vat.toFixed(2)}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:1rem; color:var(--text-muted)">
                    <span>BTW (${p.summary.vat_rate}%)</span>
                    <span>€ ${p.summary.vat_amount.toFixed(2)}</span>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:1.4rem; font-weight:700; color:#fff; border-top:1px solid rgba(255,255,255,0.1); padding-top:1rem;">
                    <span>TOTAAL</span>
                    <span style="color:#60a5fa">€ ${p.summary.total.toFixed(2)}</span>
                </div>
            </div>
            
            <div id="payment-mgmt-section" class="glass" style="margin-top:2rem; padding:1.25rem; border:1px solid rgba(255,255,255,0.05); text-align: left;">
                <h4 style="margin:0 0 1rem 0; font-size:1rem; color:var(--primary);">🏦 Betaling & Incasso Beheer</h4>
                
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 1rem; padding-bottom:1rem; border-bottom:1px solid rgba(255,255,255,0.1);">
                    <div>
                        <strong>Automatische Incasso Gevraagd</strong><br>
                        <span id="incasso-status-text" style="font-size:0.85rem; color:var(--text-muted);">
                            Laden...
                        </span>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" id="toggle-allow-incasso" onchange="toggleAllowIncasso()">
                        <span class="slider round"></span>
                    </label>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <strong>Factuur Betalingsstatus</strong><br>
                        <span id="payment-status-text" style="font-size:0.85rem; color:var(--text-muted);">Openstaand</span>
                    </div>
                    <div>
                        <button id="btn-mark-paid" class="btn btn-sm btn-ghost" onclick="markAsPaid()" style="display:none; color:#34d399; border-color:#34d399;">
                            ✓ Markeer Betaald
                        </button>
                        <button id="btn-mark-unpaid" class="btn btn-sm btn-ghost" onclick="markAsUnpaid()" style="display:none; color:#fbbf24; border-color:#fbbf24;">
                            Markeer Openstaand
                        </button>
                    </div>
                </div>
            </div>

            <div class="modal-actions" style="margin-top:2rem; justify-content: center; flex-direction: column; gap:0.75rem;">
                <!-- Batch Mode Buttons -->
                <div id="batch-action-buttons" style="display:none; width: 100%; flex-direction: column; gap: 0.75rem;">
                    <div style="display: flex; align-items: center; justify-content: center; background: rgba(52, 211, 153, 0.1); padding: 0.75rem; border-radius: 8px; margin-bottom: 0.5rem;">
                        <label class="checkbox-container" style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.9rem;">
                            <input type="checkbox" id="batch-auto-send" checked>
                            <span>Factuur direct per e-mail versturen</span>
                        </label>
                    </div>
                    <button class="btn btn-primary btn-lg" onclick="saveBatchInvoice()" style="width:100%; font-size:1.1rem; padding:1.25rem;">
                        💾 Opslaan & Volgende
                    </button>
                    <button class="btn btn-secondary" onclick="skipBatchInvoice()"> Sla over </button>
                </div>

                <!-- Normal Mode Buttons -->
                <div id="normal-action-buttons" style="display: flex; width: 100%; flex-direction: column; gap: 0.75rem;">
                    <button id="btn-save-invoice" class="btn btn-primary btn-lg" onclick="saveInvoiceResult()" style="width:100%; font-size:1rem; padding:1rem;">
                        💾 Factuur Berekening Definitief Maken
                    </button>
                    <button id="btn-send-invoice" class="btn btn-secondary btn-lg" onclick="sendInvoiceEmail()" style="width:100%; font-size:1rem; padding:1rem; display:none;">
                        ✉️ Factuur per E-mail Versturen
                    </button>
                </div>
            </div>
        </div>
    `;
    content.innerHTML = html;

    // Load active lot to populate settings
    const activeLot = allLots.find(l => l.id == currentLotId);
    if (activeLot) {
        document.getElementById('toggle-allow-incasso').checked = activeLot.allow_direct_debit == 1;
        
        let mandateTxt = "Ja, klant kan dit aanvragen.";
        if (activeLot.allow_direct_debit == 0) mandateTxt = "Nee, verborgen voor de klant.";
        else if (activeLot.incasso_mandate_date) mandateTxt = `Machtiging ontvangen op: ${activeLot.incasso_mandate_date.substring(0,10)} ✅`;
        document.getElementById('incasso-status-text').innerHTML = mandateTxt;

        if (activeLot.payment_status === 'paid') {
            document.getElementById('payment-status-text').textContent = '✅ Betaald';
            document.getElementById('payment-status-text').style.color = '#34d399';
            document.getElementById('btn-mark-unpaid').style.display = 'inline-block';
            
            // Knop factuur sturen verbergen als het al betaald is? Nee, laten we hem houden.
            document.getElementById('btn-save-invoice').style.display = 'none';
            document.getElementById('btn-send-invoice').style.display = 'block';
        } else if (activeLot.payment_status === 'pending') {
            document.getElementById('payment-status-text').textContent = '⚠️ Openstaand';
            document.getElementById('payment-status-text').style.color = '#fbbf24';
            document.getElementById('btn-mark-paid').style.display = 'inline-block';
            
            // Reeds gefactureerd: laat mail knop zien
            document.getElementById('btn-save-invoice').style.display = 'none';
            document.getElementById('btn-send-invoice').style.display = 'block';
        } else {
            // Geen status (nog niet uitgerekend)
            document.getElementById('payment-status-text').textContent = 'Berekening nog niet definitief opgeslagen';
        }
    }

    // Toggle Batch UI
    const isBatch = isInBatchMode;
    document.getElementById('batch-controls').style.display = isBatch ? 'flex' : 'none';
    document.getElementById('batch-action-buttons').style.display = isBatch ? 'flex' : 'none';
    document.getElementById('normal-action-buttons').style.display = isBatch ? 'none' : 'flex';

    if (isBatch) {
        document.getElementById('batch-index-text').textContent = `Factuur ${currentBatchIndex + 1} van ${invoicingQueue.length}`;
    }
}

// ----- Nieuwe Incasso & Betaling functies -----

async function toggleAllowIncasso() {
    const isAllowed = document.getElementById('toggle-allow-incasso').checked ? 1 : 0;
    try {
        const response = await fetch('../../backend/api/admin.php?action=update-lot-settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lot_id: currentLotId, allow_direct_debit: isAllowed })
        });
        const data = await response.json();
        if (data.success) {
            showToast('Incasso-instelling opgeslagen', 'success');
            // update local lot context gracefully
            const lot = allLots.find(l => l.id == currentLotId);
            if(lot) lot.allow_direct_debit = isAllowed;
            
            let mandateTxt = isAllowed ? "Ja, klant kan dit aanvragen." : "Nee, verborgen voor de klant.";
            if (isAllowed && lot && lot.incasso_mandate_date) mandateTxt = `Machtiging ontvangen op: ${lot.incasso_mandate_date.substring(0,10)} ✅`;
            document.getElementById('incasso-status-text').innerHTML = mandateTxt;
            
            loadDashboard(); // silently refresh background
        } else {
            throw new Error(data.error);
        }
    } catch(e) {
        showToast(e.message, 'error');
        document.getElementById('toggle-allow-incasso').checked = !isAllowed;
    }
}

async function markAsPaid() { updatePaymentStatus('paid'); }
async function markAsUnpaid() { updatePaymentStatus('pending'); }

async function updatePaymentStatus(status) {
    try {
        const response = await fetch('../../backend/api/admin.php?action=update-payment-status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lot_id: currentLotId, status: status })
        });
        const data = await response.json();
        if (data.success) {
            showToast(`Status gewijzigd naar: ${status === 'paid' ? 'Betaald' : 'Openstaand'}`, 'success');
            const lot = allLots.find(l => l.id == currentLotId);
            if(lot) lot.payment_status = status;
            
            // Reload the preview specifically to update the ui
            const corr = parseFloat(document.getElementById('inv-correction').value) || 0;
            const reason = document.getElementById('inv-reason').value;
            loadInvoicePreview(currentLotId, corr, reason);
            
            loadDashboard(); // visually update main table
        } else {
            throw new Error(data.error);
        }
    } catch(e) {
        showToast(e.message, 'error');
    }
}

// ----------------------------------------------

async function sendInvoiceEmail() {
    const btn = document.getElementById('btn-send-invoice');
    btn.disabled = true;
    btn.textContent = 'Bezig met verzenden...';

    try {
        const response = await fetch(`../../backend/api/admin.php?action=send-invoice&lot_id=${currentLotId}`);
        const data = await response.json();
        
        if (data.success) {
            showToast('Factuur succesvol verzonden!', 'success');
        } else throw new Error(data.error);
    } catch (e) {
        showToast(e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = '✉️ Factuur per E-mail Versturen';
    }
}

function updateInvoiceCorrection() {
    const corr = parseFloat(document.getElementById('inv-correction').value) || 0;
    const reason = document.getElementById('inv-reason').value;
    loadInvoicePreview(currentLotId, currentOccupancyId, corr, reason);
}

async function saveInvoiceResult() {
    if (!currentPreviewData) return;
    
    confirmDialog('Factuur opslaan', 'Hiermee sla je de berekening definitief op in de database en zet je de status op afgerond. Doorgaan?', async () => {
        try {
            const response = await fetch('../../backend/api/admin.php?action=save-billing-result', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ preview: currentPreviewData })
            });

            const data = await response.json();
            if (data.success) {
                showToast('Factuurberekening opgeslagen!', 'success');
                // Toon mail knop na opslaan
                document.getElementById('btn-save-invoice').style.display = 'none';
                document.getElementById('btn-send-invoice').style.display = 'block';
                loadDashboard();
            } else throw new Error(data.error);
        } catch (e) {
            showToast(e.message, 'error');
        }
    });
}

// ================================================================
// BEWONER BEHEER
// ================================================================

function openAddResidentModal(lotId, lotNr) {
    const title = document.getElementById('resident-modal-title');
    const desc = document.getElementById('resident-modal-desc');
    if (title) title.textContent = '👤 Bewonergegevens wijzigen';
    if (desc) desc.innerHTML = `Bewerk bewonergegevens voor kavel <strong>#${lotNr}</strong>.`;
    
    document.getElementById('resident-lot-id').value = lotId;
    document.getElementById('resident-action').value = 'add';
    document.getElementById('resident-name').value = '';
    document.getElementById('resident-email').value = '';
    toggleModal('resident-modal-overlay', true);
}

function openEditResidentModal(lotId, name, email) {
    const title = document.getElementById('resident-modal-title');
    const desc = document.getElementById('resident-modal-desc');
    if (title) title.textContent = '👤 Bewoner Wijzigen';
    if (desc) desc.innerHTML = `Bewerk bewonergegevens.`;
    
    document.getElementById('resident-lot-id').value = lotId;
    document.getElementById('resident-action').value = 'edit';
    document.getElementById('resident-name').value = name;
    document.getElementById('resident-email').value = email;
    toggleModal('resident-modal-overlay', true);
}

async function submitResident(e) {
    e.preventDefault();
    const action = document.getElementById('resident-action').value;
    const lotId = document.getElementById('resident-lot-id').value;
    const name = document.getElementById('resident-name').value;
    const email = document.getElementById('resident-email').value;

    const endpoint = action === 'add' ? 'assign-resident' : 'update-resident';
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'Bezig met opslaan...';

    try {
        const response = await fetch(`../../backend/api/admin.php?action=${endpoint}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lot_id: lotId, name, email })
        });
        const data = await response.json();

        if (data.success) {
            showToast(action === 'add' ? 'Nieuwe bewoner gekoppeld!' : 'Bewoner gegevens bijgewerkt!', 'success');
            toggleModal('resident-modal-overlay', false);
            if (currentLotId) viewHistory(currentLotId);
            loadDashboard();
        } else throw new Error(data.error);
    } catch (err) {
        showToast(err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = '💾 Opslaan';
    }
}

// ================================================================
// OCR TEST TOOL
// ================================================================

async function runOcrTest() {
    const fileInput = document.getElementById('test-ocr-upload');
    const resultBox = document.getElementById('test-ocr-result');
    const btn = document.getElementById('btn-run-ocr-test');

    if (!fileInput.files.length) {
        showToast('Selecteer eerst een foto', 'error');
        return;
    }

    btn.disabled = true;
    btn.textContent = '🔍 Scannen...';
    resultBox.style.display = 'block';
    resultBox.textContent = 'Bezig met AI-verwerking...';
    resultBox.style.color = 'var(--text-muted)';

    try {
        const file = fileInput.files[0];
        const base64 = await new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.readAsDataURL(file);
        });

        const response = await fetch('../../backend/api/admin.php?action=test-ocr', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ image: base64 })
        });
        const data = await response.json();

        if (data.success) {
            resultBox.textContent = JSON.stringify(data.result, null, 2);
            resultBox.style.color = '#4ade80';
            showToast('Scan voltooid!', 'success');
        } else throw new Error(data.error);
    } catch (err) {
        resultBox.textContent = 'Fout: ' + err.message;
        resultBox.style.color = '#f87171';
        showToast('Scan mislukt', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = '🔍 Start Test Scan';
    }
}

// ================================================================
// ADMINISTRATOR BEHEER
// ================================================================

async function loadAdmins() {
    const container = document.getElementById('admin-list-container');
    container.innerHTML = '<div style="color:var(--text-muted); font-size:0.85rem;">Beheerders laden...</div>';
    
    try {
        const response = await fetch('../../backend/api/admin.php?action=get-admins');
        const data = await response.json();
        
        if (data.success) {
            if (data.admins.length === 0) {
                container.innerHTML = '<div style="color:var(--text-muted); font-size:0.85rem; padding: 1rem; border: 1px dashed rgba(255,255,255,0.1); border-radius: 8px;">Geen andere beheerders gevonden.</div>';
                return;
            }
            
            container.innerHTML = data.admins.map(admin => `
                <div class="admin-item">
                    <div class="admin-info">
                        <h4>${admin.name || 'Geen naam'}</h4>
                        <p>${admin.email}</p>
                    </div>
                    <div class="admin-actions">
                        <button class="btn-icon-only" onclick="openAdminUserModal(${JSON.stringify(admin).replace(/"/g, '&quot;')})" title="Wijzigen">
                            ✏️
                        </button>
                        <button class="btn-icon-only danger" onclick="deleteAdmin(${admin.id})" title="Verwijderen">
                            🗑️
                        </button>
                    </div>
                </div>
            `).join('');
        }
    } catch (e) {
        container.innerHTML = '<div style="color:#f87171; font-size:0.85rem;">Fout bij laden beheerders.</div>';
    }
}

function openAdminUserModal(admin = null) {
    const title = document.getElementById('admin-user-modal-title');
    const roleGroup = document.getElementById('admin-role-group');
    
    if (admin) {
        title.textContent = '👤 Beheerder Wijzigen';
        document.getElementById('admin-user-id').value = admin.id;
        document.getElementById('admin-name').value = admin.name || '';
        document.getElementById('admin-email').value = admin.email;
        if (roleGroup) roleGroup.style.display = 'none'; // Verberg rol voor bestaande admins (voorlopig altijd admin)
    } else {
        title.textContent = '➕ Nieuwe Beheerder';
        document.getElementById('admin-user-id').value = '';
        document.getElementById('admin-name').value = '';
        document.getElementById('admin-email').value = '';
        if (roleGroup) roleGroup.style.display = 'block';
    }
    
    toggleModal('admin-user-modal-overlay', true);
}

async function saveAdminUser(e) {
    e.preventDefault();
    const id = document.getElementById('admin-user-id').value;
    const name = document.getElementById('admin-name').value;
    const email = document.getElementById('admin-email').value;
    
    const action = id ? 'update-admin' : 'add-admin';
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'Opslaan...';
    
    try {
        const response = await fetch(`../../backend/api/admin.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, name, email })
        });
        const data = await response.json();
        
        if (data.success) {
            showToast('Beheerder opgeslagen', 'success');
            toggleModal('admin-user-modal-overlay', false);
            loadAdmins();
        } else throw new Error(data.error);
    } catch (err) {
        showToast(err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = '💾 Opslaan';
    }
}

async function deleteAdmin(id) {
    confirmDialog('Beheerder verwijderen', 'Weet je zeker dat je deze beheerder wilt verwijderen? Deze persoon kan dan niet meer inloggen op het dashboard.', async () => {
        try {
            const response = await fetch(`../../backend/api/admin.php?action=delete-admin&id=${id}`);
            const data = await response.json();
            if (data.success) {
                showToast('Beheerder verwijderd', 'success');
                loadAdmins();
            } else throw new Error(data.error);
        } catch (e) {
            showToast(e.message, 'error');
        }
    });
}

// ================================================================
// LIVE SCANNER TEST LOGICA (ADMIN)
// ================================================================

let adminCameraStream = null;

async function startAdminScanner() {
    const video = document.getElementById('admin-video');
    const resultBox = document.getElementById('admin-scan-result');
    resultBox.style.display = 'none';
    
    if (adminCameraStream) stopAdminScanner();

    try {
        const constraints = {
            video: { facingMode: { ideal: 'environment' } },
            audio: false
        };
        adminCameraStream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = adminCameraStream;
    } catch (err) {
        showToast('Camera kon niet worden gestart: ' + err.message, 'error');
        toggleModal('admin-scanner-overlay', false);
    }
}

function stopAdminScanner() {
    if (adminCameraStream) {
        adminCameraStream.getTracks().forEach(track => track.stop());
        adminCameraStream = null;
    }
    const video = document.getElementById('admin-video');
    if (video) video.srcObject = null;
}

async function captureAdminPhoto() {
    const video = document.getElementById('admin-video');
    const canvas = document.createElement('canvas'); // Temp canvas
    const resultBox = document.getElementById('admin-scan-result');
    const btn = document.getElementById('admin-capture-btn');
    
    // Crop area logic similar to app.js
    const vw = video.videoWidth;
    const vh = video.videoHeight;
    const cw = video.offsetWidth;
    const ch = video.offsetHeight;
    
    const videoRatio = vw / vh;
    const containerRatio = cw / ch;
    
    let sx, sy, sw, sh;
    if (videoRatio > containerRatio) {
        const scale = vh / ch;
        const visibleWidth = cw * scale;
        const offset = (vw - visibleWidth) / 2;
        sw = visibleWidth * 0.85;
        sh = (ch * 0.28) * scale;
        sx = offset + (visibleWidth * 0.075);
        sy = (ch * 0.30) * scale;
    } else {
        const scale = vw / cw;
        const visibleHeight = ch * scale;
        const offset = (vh - visibleHeight) / 2;
        sw = vw * 0.85;
        sh = (ch * 0.28) * scale;
        sx = vw * 0.075;
        sy = offset + (ch * 0.30) * scale;
    }

    canvas.width = sw;
    canvas.height = sh;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
    
    const imgDataUrl = canvas.toDataURL('image/jpeg', 0.85);

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Bezig...';
    resultBox.style.display = 'block';
    resultBox.innerHTML = '<div style="color:var(--text-muted); font-size:0.85rem;">🤖 AI analyse uitvoeren...</div>';

    try {
        const response = await fetch('../../backend/api/admin.php?action=test-ocr', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ image: imgDataUrl })
        });
        const data = await response.json();
        
        if (data.success) {
            const r = data.result;
            resultBox.innerHTML = `
                <div style="color:#4ade80; font-weight:600; font-size:1.1rem; margin-bottom:0.5rem;">✅ Analyse geslaagd</div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; text-align:left; font-size:0.85rem;">
                    <div style="background:rgba(255,255,255,0.05); padding:0.5rem; border-radius:6px;">
                       <span style="color:var(--text-muted)">Meterstand</span><br>
                       <strong style="font-size:1.1rem; color:#fff;">${r.reading || '---'}</strong>
                    </div>
                    <div style="background:rgba(255,255,255,0.05); padding:0.5rem; border-radius:6px;">
                       <span style="color:var(--text-muted)">Meternummer</span><br>
                       <strong style="color:#fff;">${r.meter_number || 'Geen'}</strong>
                    </div>
                </div>
                <div style="margin-top:0.75rem; font-size:0.75rem; color:var(--text-muted);">
                   Confidence: ${Math.round(r.confidence * 100)}% | Model: ${r.model}
                </div>
            `;
            showToast('Scan resultaat ontvangen!', 'success');
        } else throw new Error(data.error);
    } catch (e) {
        resultBox.innerHTML = `<div style="color:#f87171; font-weight:600;">❌ Fout bij scannen:</div> <div style="font-size:0.8rem;">${e.message}</div>`;
        showToast('Scan mislukt', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '📷 Maak foto & test';
    }
}

async function runManualOcrTest() {
    const fileInput = document.getElementById('test-ocr-upload');
    const resultBox = document.getElementById('test-ocr-result');
    const btn = document.getElementById('btn-run-ocr-test');
    
    if (!fileInput.files || fileInput.files.length === 0) {
        showToast('Selecteer eerst een bestand', 'warning');
        return;
    }
    
    const file = fileInput.files[0];
    const reader = new FileReader();
    
    btn.disabled = true;
    btn.textContent = '⏳ Verwerken...';
    resultBox.style.display = 'block';
    resultBox.textContent = '🤖 AI analyse wordt uitgevoerd...';
    resultBox.style.color = '#4ade80';

    reader.onload = async (e) => {
        const imageData = e.target.result;
        
        try {
            const response = await fetch('../../backend/api/admin.php?action=test-ocr', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ image: imageData })
            });
            const data = await response.json();
            
            if (data.success) {
                resultBox.textContent = JSON.stringify(data.result, null, 2);
                showToast('Bestand succesvol gescand!', 'success');
            } else throw new Error(data.error);
        } catch (err) {
            resultBox.textContent = 'Fout: ' + err.message;
            resultBox.style.color = '#f87171';
            showToast('Scan mislukt', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = '📁 Bestand Uploaden';
        }
    };
    
    reader.readAsDataURL(file);
}

// ================================================================
// NIEUWE BEWONER FLOW
// ================================================================

function openNewResidentModal(lotId) {
    const lot = allLots.find(l => l.id == lotId);
    if (!lot) return;

    document.getElementById('nr-lot-id').value = lotId;
    document.getElementById('new-resident-title').textContent = `Nieuwe Bewoner: Kavel #${lot.lot_number}`;
    document.getElementById('nr-name').value = '';
    document.getElementById('nr-email').value = '';
    
    // Default datum is vandaag
    document.getElementById('nr-start-date').value = new Date().toISOString().substring(0, 10);
    
    toggleModal('modal-new-resident', true);
}

function initNewResidentForm() {
    const form = document.getElementById('form-new-resident');
    if (!form) return;

    form.onsubmit = async (e) => {
        e.preventDefault();
        const lotId = document.getElementById('nr-lot-id').value;
        const payload = {
            lot_id: lotId,
            name: document.getElementById('nr-name').value,
            email: document.getElementById('nr-email').value,
            start_date: document.getElementById('nr-start-date').value
        };

        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Bezig met activeren...';

        try {
            const response = await fetch('../../backend/api/admin.php?action=save-new-resident', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (data.success) {
                showToast('Nieuwe bewoner succesvol geactiveerd!', 'success');
                toggleModal('modal-new-resident', false);
                loadDashboard();
            } else throw new Error(data.error);
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Bewoner Activeren';
        }
    };
}

// ================================================================
// FACTURATIE DASHBOARD & BATCH LOGICA
// ================================================================

let invoicingQueue = [];
let currentBatchIndex = -1;
let isInBatchMode = false;

async function openInvoicesDashboard() {
    toggleModal('invoices-modal-overlay', true);
    await loadInvoicingStats();
}

async function loadInvoicingStats() {
    const listBody = document.getElementById('inv-ready-list');
    listBody.innerHTML = '<tr><td colspan="3" class="table-loading">Laden...</td></tr>';

    try {
        const response = await fetch('../../backend/api/admin.php?action=get-invoicing-stats');
        const data = await response.json();

        if (data.success) {
            const s = data.stats;
            document.getElementById('inv-stat-ready').textContent = s.ready_count || 0;
            document.getElementById('inv-stat-invoiced').textContent = s.invoiced_count || 0;
            document.getElementById('inv-stat-sent').textContent = s.sent_count || 0;

            const perc = s.total_built > 0 ? (s.sent_count / s.total_built) * 100 : 0;
            document.getElementById('inv-progress-bar').style.width = `${perc}%`;
            document.getElementById('inv-progress-text').textContent = `${s.sent_count || 0} van de ${s.total_built || 0} bebouwde kavels verstuurd`;

            // List
            if (data.ready_lots.length === 0) {
                listBody.innerHTML = '<tr><td colspan="3" class="table-empty">Geen kavels klaar voor facturatie.</td></tr>';
                document.getElementById('btn-start-batch-invoicing').disabled = true;
            } else {
                listBody.innerHTML = data.ready_lots.map(l => `
                    <tr>
                        <td><strong>#${l.lot_number}</strong></td>
                        <td>${l.name || 'Bewoner'}</td>
                        <td style="text-align: right;">
                             <button class="btn btn-ghost btn-xs" onclick="viewHistory(${l.lot_id}, false, ${l.occupancy_id}); toggleModal('invoices-modal-overlay', false); setTimeout(()=>switchModalTab('tab-invoice'), 300)">Review</button>
                        </td>
                    </tr>
                `).join('');
                document.getElementById('btn-start-batch-invoicing').disabled = false;
                invoicingQueue = data.ready_lots; // Store for batch
            }
        } else {
            console.error('[loadInvoicingStats] API Error:', data.error);
            listBody.innerHTML = `<tr><td colspan="3" class="table-empty">Fout: ${data.error || 'Onbekende fout'}</td></tr>`;
        }
    } catch (e) {
        console.error('[loadInvoicingStats] Fetch Error:', e);
        listBody.innerHTML = `<tr><td colspan="3" class="table-empty">Netwerkfout bij laden.</td></tr>`;
        showToast('Fout bij laden facturatiegegevens', 'error');
    }
}

function startBatchInvoicing() {
    if (invoicingQueue.length === 0) {
        showToast('Geen kavels in de wachtrij.', 'info');
        return;
    }
    
    confirmDialog('Batch Verwerking Starten', `Je staat op het punt om ${invoicingQueue.length} facturen te gaan reviewen. Wil je beginnen?`, () => {
        isInBatchMode = true;
        currentBatchIndex = 0;
        toggleModal('invoices-modal-overlay', false);
        showBatchInvoice();
    });
}

function showBatchInvoice() {
    if (currentBatchIndex < 0 || currentBatchIndex >= invoicingQueue.length) {
        finishBatchInvoicing();
        return;
    }

    const item = invoicingQueue[currentBatchIndex];
    viewHistory(item.lot_id, false, item.occupancy_id); // FALSE: we willen isInBatchMode behouden!
    
    // Switch to invoice tab after a small delay to ensure modal is setup
    setTimeout(() => {
        switchModalTab('tab-invoice');
        loadInvoicePreview(item.lot_id, item.occupancy_id);
    }, 400);
}

async function saveBatchInvoice() {
    if (!currentPreviewData) return;
    
    const autoSend = document.getElementById('batch-auto-send').checked;
    const btn = event.target;
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Bezig met verwerken...';

    try {
        // 1. Opslaan
        const saveRes = await fetch('../../backend/api/admin.php?action=save-billing-result', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ preview: currentPreviewData })
        });
        const saveData = await saveRes.json();
        
        if (!saveData.success) throw new Error(saveData.error);

        // 2. Eventueel versturen
        if (autoSend) {
            btn.textContent = 'Factuur versturen...';
            const sendRes = await fetch(`../../backend/api/admin.php?action=send-invoice&lot_id=${currentLotId}`);
            const sendData = await sendRes.json();
            if (!sendData.success) showToast(`Factuur opgeslagen, maar verzenden mislukt: ${sendData.error}`, 'warning');
            else showToast(`Factuur #${invoicingQueue[currentBatchIndex].lot_number} opgeslagen en verzonden.`, 'success');
        } else {
            showToast(`Factuur #${invoicingQueue[currentBatchIndex].lot_number} opgeslagen.`, 'success');
        }

        // 3. Volgende
        currentBatchIndex++;
        if (currentBatchIndex < invoicingQueue.length) {
            showBatchInvoice();
        } else {
            finishBatchInvoicing();
        }

    } catch (err) {
        showToast(err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

function skipBatchInvoice() {
    currentBatchIndex++;
    if (currentBatchIndex < invoicingQueue.length) {
        showBatchInvoice();
    } else {
        finishBatchInvoicing();
    }
}

function stopBatchInvoicing() {
    confirmDialog('Stop Batch', 'Weet je zeker dat je wilt stoppen met de batch verwerking?', () => {
        finishBatchInvoicing();
    });
}

function finishBatchInvoicing() {
    isInBatchMode = false;
    invoicingQueue = [];
    currentBatchIndex = -1;
    closeModal();
    loadDashboard();
    showToast('Batch verwerking afgerond.', 'success');
    setTimeout(openInvoicesDashboard, 500);
}

