/**
 * Main Application Logic for Horsterwold PWA
 */

// Local state for meter readings
const meterState = {
    water: { completed: false, val: null, scanAttempts: 0 },
    gas: { completed: false, val: null, scanAttempts: 0 },
    elec: { completed: false, val: null, scanAttempts: 0 }
};

let isManualCorrectionActive = false;

let currentMeterContext = null;
let cameraStream = null;
let currentUser = null; 
let currentCameraDeviceId = null;
let availableVideoDevices = [];

document.addEventListener('DOMContentLoaded', () => {
    init();

    window.addEventListener('popstate', (e) => {
        // As long as camera/flow is active, back button should just close it 
        // without leaving the app page.
        if (currentMeterContext && document.getElementById('photo-screen').classList.contains('active')) {
            stopCamera();
            currentMeterContext = null;
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('test_scanner') === 'true') {
                window.location.href = 'admin/';
                return;
            }
            showScreen('dashboard-screen');
        }
    });
});

function init() {
    const loginForm = document.getElementById('login-form');
    const loginFeedback = document.getElementById('login-feedback');
    
    // Check if there is a token in the URL (Magic Link return)
    const urlParams = new URLSearchParams(window.location.search);
    const token = urlParams.get('token') || urlParams.get('t');
    const isTestScannerMode = urlParams.get('test_scanner') === 'true';

    if (isTestScannerMode) {
        // Test mode setup
        document.getElementById('scan-instruction-text').innerHTML = `Scanner Testmodus<br><span style="font-size: 0.8em; font-weight: normal; color: var(--text-muted);">(Foto wordt niet opgeslagen)</span>`;
        const closeBtn = document.querySelector('.btn-camera-close');
        if (closeBtn) closeBtn.title = "Terug naar Admin";
        
        startMeterFlow('test');
        return; // Skip normal auth flow
    } else if (token) {
        handleTokenVerification(token);
    } else {
        checkExistingSession();
    }

    // Handle Login Form
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = document.getElementById('email').value;
            
            loginFeedback.style.display = 'block';
            loginFeedback.textContent = 'Bezig met versturen...';
            loginFeedback.style.color = 'var(--text-muted)';

            try {
                // Mock delay for UI
                loginFeedback.textContent = 'Check je mailbox voor de inloglink!';
                loginFeedback.style.color = 'var(--primary)';
                loginForm.reset();
            } catch (error) {
                loginFeedback.textContent = error.message;
                loginFeedback.style.color = '#f87171';
            }
        });
    }

    // Capture Button setup
    const btnCapture = document.getElementById('btn-capture');
    if (btnCapture) {
        btnCapture.addEventListener('click', capturePhoto);
    }

    // Handle Logout
    const btnLogout = document.getElementById('btn-logout');
    if (btnLogout) {
        btnLogout.addEventListener('click', async () => {
            await API.request('login', { body: JSON.stringify({ action: 'logout' }) }).catch(console.error);
            window.location.href = 'logout.html';
        });
    }
}

async function handleTokenVerification(token) {
    try {
        const response = await API.verifyToken(token);
        const user = response.user;
        window.history.replaceState({}, document.title, window.location.pathname);
        showDashboard(user);
    } catch (error) {
        console.warn('Inloggen backend mislukt, door met demo data...');
        // Fallback demo user
        showDashboard({ name: 'Fam. Demo', lot_number: 142 });
    }
}

async function checkExistingSession() {
    try {
        const response = await API.checkSession();
        if (response.success && response.user) {
            showDashboard(response.user);
        } else {
            showScreen('login-screen');
        }
    } catch (error) {
        showScreen('login-screen');
    }
}

function showDashboard(user) {
    if (user) {
        currentUser = user; // Opslaan voor later gebruik in de flow
        document.getElementById('user-name').textContent = user.name || user.email;
        document.getElementById('lot-number').textContent = user.lot_number ? `#${user.lot_number}` : '#--'; 
    }
    
    // Update Meter Buttons State
    updateDashboardUI();
    showScreen('dashboard-screen');
}

function updateDashboardUI() {
    ['water', 'gas', 'elec'].forEach(type => {
        const btn = document.getElementById(`btn-meter-${type}`);
        const statusText = document.getElementById(`status-${type}`);
        
        if (meterState[type].completed) {
            btn.classList.add('completed');
            btn.disabled = true;
            statusText.innerHTML = '✅ Ontvangen';
        } else {
            btn.classList.remove('completed');
            btn.disabled = false;
            statusText.innerHTML = 'Actie vereist';
        }
    });
}

function showScreen(screenId) {
    document.querySelectorAll('section').forEach(s => s.classList.remove('active'));
    const target = document.getElementById(screenId);
    if (target) target.classList.add('active');
}

// --- Meter Flow Logic ---

// (Removed duplicate startMeterFlow)

function cancelMeterFlow() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('test_scanner') === 'true') {
        window.location.href = 'admin/';
        return;
    }

    if (history.state && history.state.screen === 'photo-screen') {
        history.back(); // will trigger popstate
    } else {
        stopCamera();
        currentMeterContext = null;
        showScreen('dashboard-screen');
    }
}

async function startCamera(preferredDeviceId = null) {
    const video = document.getElementById('camera-stream');
    
    stopCamera();

    // Initialiseer lijst met apparaten als we die nog niet hebben
    if (availableVideoDevices.length === 0) {
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            availableVideoDevices = devices.filter(d => d.kind === 'videoinput');
        } catch (e) { console.warn("Kon apparaten niet enumereren", e); }
    }

    try {
        const constraints = {
            video: { 
                width: { ideal: 1920 },
                height: { ideal: 1080 }
            },
            audio: false
        };

        // Als we expliciet een ID hebben gekregen (via wissel-knop of slimme detectie)
        if (preferredDeviceId) {
            constraints.video.deviceId = { exact: preferredDeviceId };
        } else {
            // Anders: probeer 'environment' (achterkant)
            constraints.video.facingMode = { ideal: 'environment' };
        }
        
        cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = cameraStream;

        // Onthoud welke camera we nu echt gebruiken
        const track = cameraStream.getVideoTracks()[0];
        if (track) {
            const settings = track.getSettings();
            currentCameraDeviceId = settings.deviceId;
            
            // Probeer namen op te halen (nu we toestemming hebben)
            if (availableVideoDevices.length === 0 || !availableVideoDevices[0].label) {
                const devices = await navigator.mediaDevices.enumerateDevices();
                availableVideoDevices = devices.filter(d => d.kind === 'videoinput');
            }
        }
    } catch (err) {
        console.error("Camera error:", err);
        // Fallback: Als 'exact' mislukt, probeer het dan zonder ID
        if (preferredDeviceId) {
            console.log("Fallback naar standaard camera...");
            await startCamera(null);
        } else {
            alert("We konden de camera niet starten. Controleer je instellingen.");
        }
    }
}

async function switchCamera() {
    if (availableVideoDevices.length < 2) {
        // Mogelijk nog geen labels, probeer opnieuw te laden
        const devices = await navigator.mediaDevices.enumerateDevices();
        availableVideoDevices = devices.filter(d => d.kind === 'videoinput');
        if (availableVideoDevices.length < 2) return;
    }
    
    let currentIndex = availableVideoDevices.findIndex(d => d.deviceId === currentCameraDeviceId);
    // Als we het niet kunnen vinden, pak de eerste
    let nextIndex = (currentIndex === -1) ? 0 : (currentIndex + 1) % availableVideoDevices.length;
    
    const nextDevice = availableVideoDevices[nextIndex];
    console.log("Wisselen naar camera:", nextDevice.label || nextDevice.deviceId);
    await startCamera(nextDevice.deviceId);
}

/**
 * Helpertje om specifiek de achtercamera te zoeken op basis van labels (voor Surface/Windows)
 */
async function findBackCameraId() {
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const videoInputs = devices.filter(d => d.kind === 'videoinput');
        
        // Zoek naar trefwoorden die duiden op de achtercamera
        const backKeywords = ['back', 'rear', 'world', 'achter', 'omgeving'];
        const backCamera = videoInputs.find(d => {
            const label = d.label.toLowerCase();
            return backKeywords.some(kw => label.includes(kw));
        });
        
        return backCamera ? backCamera.deviceId : null;
    } catch (e) { return null; }
}

function stopCamera() {
    if (stabilityInterval) {
        clearInterval(stabilityInterval);
        stabilityInterval = null;
    }
    const steadyStatus = document.getElementById('steady-status');
    if (steadyStatus) steadyStatus.style.opacity = 0;

    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
    const video = document.getElementById('camera-stream');
    if (video) video.srcObject = null;
}

/**
 * Simulator: used for testing when no camera is available
 */
async function simulateCapture() {
    const canvas = document.getElementById('canvas-capture');
    canvas.width = 640;
    canvas.height = 480;
    const ctx = canvas.getContext('2d');
    
    // Draw a fake meter
    ctx.fillStyle = '#1a1a1a';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#fff';
    ctx.font = 'bold 80px Monospace';
    ctx.textAlign = 'center';
    
    // Some random digits that look like a meter reading
    const fakeReading = Math.floor(Math.random() * 90000) + 10000;
    ctx.fillText(fakeReading.toString(), canvas.width / 2, canvas.height / 2 + 30);
    
    // 1. Maak een schone versie voor de OCR (zonder watermark)
    const cleanImgDataUrl = canvas.toDataURL('image/jpeg');
    
    // 2. Voeg nu pas het watermerk toe voor display/archivering
    drawWatermark(ctx, canvas.width, canvas.height);
    const watermarkedImgDataUrl = canvas.toDataURL('image/jpeg');
    
    document.getElementById('verify-image').src = watermarkedImgDataUrl;

    // Show loading state
    document.getElementById('ocr-result').value = "";
    document.getElementById('ocr-result').placeholder = "Uitlezen...";
    
    stopCamera();
    showScreen('verify-screen');

    // 3. Gebruik de SCHONE versie voor OCR
    await processOCR(cleanImgDataUrl);
}

/**
 * Shared logic to handle the OCR API call
 */
async function processOCR(imgDataUrl) {
    try {
        const response = await fetch('../backend/api/meter_upload.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                image: imgDataUrl,
                type: currentMeterContext
            })
        });

        const data = await response.json();

        if (data.success) {
            document.getElementById('ocr-result').value = data.reading;
            document.getElementById('ocr-meter-number').value = data.meter_number || "Niet gedetecteerd";
            
            // Handle Validation
            handleOCRValidation(data.validation || { valid: true, message: 'OK' });
        } else {
            console.warn("OCR fout:", data.error);
            // Fallback to manual check with placeholder if API is not yet set up
            alert("Bericht van server: " + (data.error || "Kon de meterstand niet lezen."));
        }
    } catch (error) {
        console.error("Upload error:", error);
        alert("Netwerkfout bij het uploaden van de foto. (Is je backend/config.php goed ingesteld of composer install gedraaid?)");
    } finally {
        document.getElementById('ocr-result').placeholder = "0";
        
        // Count attempt
        if (currentMeterContext && meterState[currentMeterContext]) {
            meterState[currentMeterContext].scanAttempts++;
            if (meterState[currentMeterContext].scanAttempts >= 2) {
                document.getElementById('manual-entry-box').style.display = 'block';
            }
        }
    }
}

function enableManualEntry() {
    isManualCorrectionActive = true;
    const input = document.getElementById('ocr-result');
    input.readOnly = false;
    input.focus();
    input.style.background = "rgba(192, 132, 252, 0.1)";
    input.style.border = "1px solid var(--primary)";
    
    document.getElementById('manual-entry-box').style.display = 'none';
    document.getElementById('ocr-warning-box').style.display = 'none';
    document.getElementById('btn-confirm-meter').style.display = 'block';
    document.getElementById('btn-override-ocr').style.display = 'none';
}

/**
 * Handle validation result from OCR
 */
function handleOCRValidation(validation) {
    const warningBox = document.getElementById('ocr-warning-box');
    const warningMsg = document.getElementById('ocr-warning-message');
    const btnConfirm = document.getElementById('btn-confirm-meter');
    const btnOverride = document.getElementById('btn-override-ocr');

    if (!validation.valid) {
        warningBox.style.display = 'block';
        warningMsg.textContent = validation.message;
        btnConfirm.style.display = 'none';
        btnOverride.style.display = 'block';
    } else {
        warningBox.style.display = 'none';
        btnConfirm.style.display = 'block';
        btnOverride.style.display = 'none';
    }
}

function overrideOCR() {
    const warningBox = document.getElementById('ocr-warning-box');
    const btnConfirm = document.getElementById('btn-confirm-meter');
    const btnOverride = document.getElementById('btn-override-ocr');

    warningBox.style.display = 'none';
    btnConfirm.style.display = 'block';
    btnOverride.style.display = 'none';
}

let stabilityCounter = 0;
let lastFrameData = null;
let stabilityInterval = null;

async function capturePhoto() {
    const video = document.getElementById('camera-stream');
    const canvas = document.getElementById('canvas-capture');
    const steadyStatus = document.getElementById('steady-status');
    
    if (stabilityInterval) {
        clearInterval(stabilityInterval);
        stabilityInterval = null;
        steadyStatus.style.opacity = 0;
    }

    // Get real dimensions of the video stream
    const vw = video.videoWidth;
    const vh = video.videoHeight;
    
    // Get dimensions of the container (matching CSS)
    const cw = video.offsetWidth;
    const ch = video.offsetHeight;

    // Calculate crop area based on .scan-frame CSS (width: 85%, height: 28%, top: 30%)
    // Since video is 'object-fit: cover', we need to account for hidden parts
    const videoRatio = vw / vh;
    const containerRatio = cw / ch;
    
    let sx, sy, sw, sh;
    
    if (videoRatio > containerRatio) {
        // Video is wider than container (sides cut off)
        const scale = vh / ch;
        const visibleWidth = cw * scale;
        const offset = (vw - visibleWidth) / 2;
        
        sw = visibleWidth * 0.85;
        sh = (ch * 0.28) * scale;
        sx = offset + (visibleWidth * 0.075); // 0.075 because (100-85)/2
        sy = (ch * 0.30) * scale;
    } else {
        // Video is taller than container (top/bottom cut off)
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
    
    // Draw only the cropped area
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
    
    // 1. Maak een schone versie voor de OCR (zonder watermark)
    const cleanImgDataUrl = canvas.toDataURL('image/jpeg', 0.85);
    
    // 2. Voeg nu pas het watermerk toe voor display/archivering
    drawWatermark(ctx, sw, sh);
    const watermarkedImgDataUrl = canvas.toDataURL('image/jpeg', 0.85);

    document.getElementById('verify-image').src = watermarkedImgDataUrl;

    // Show loading state
    document.getElementById('ocr-result').value = "";
    document.getElementById('ocr-result').placeholder = "Uitlezen...";

    // Transition UI
    stopCamera();
    showScreen('verify-screen');

    // 3. Gebruik de SCHONE versie voor OCR
    await processOCR(cleanImgDataUrl);
}

/**
 * Detects if the camera is steady by comparing pixel data of small samples
 */
function startStabilityCheck() {
    if (stabilityInterval) clearInterval(stabilityInterval);
    stabilityCounter = 0;
    lastFrameData = null;
    const steadyStatus = document.getElementById('steady-status');

    stabilityInterval = setInterval(() => {
        const video = document.getElementById('camera-stream');
        if (!video || video.readyState !== 4) return;

        const canvas = document.createElement('canvas'); // Small offscreen canvas
        canvas.width = 40;
        canvas.height = 40;
        const ctx = canvas.getContext('2d');
        
        // Draw the center area of the video to our tiny canvas
        ctx.drawImage(video, video.videoWidth/2 - 50, video.videoHeight/2 - 50, 100, 100, 0, 0, 40, 40);
        const currentFrameData = ctx.getImageData(0, 0, 40, 40).data;

        if (lastFrameData) {
            let diff = 0;
            for (let i = 0; i < currentFrameData.length; i += 4) {
                diff += Math.abs(currentFrameData[i] - lastFrameData[i]); // Check redness diff
            }
            
            const threshold = 1500; // Sensitivity: lower means more restrictive
            if (diff < threshold) {
                stabilityCounter++;
                if (stabilityCounter > 1) steadyStatus.style.opacity = 1;
            } else {
                stabilityCounter = 0;
                steadyStatus.style.opacity = 0;
            }

            // If stable for ~1 second (5 checks * 200ms)
            if (stabilityCounter >= 5) {
                console.log("Steady Shot triggered!");
                capturePhoto();
            }
        }
        lastFrameData = currentFrameData;
    }, 200);
}

/**
 * Adds a premium watermark to the captured image canvas.
 * Includes lot number and current timestamp.
 */
function drawWatermark(ctx, w, h) {
    const lotNumber = (currentUser && currentUser.lot_number) ? currentUser.lot_number : '---';
    const now = new Date();
    const dateStr = now.toLocaleDateString('nl-NL', { day: '2-digit', month: '2-digit', year: 'numeric' });
    const timeStr = now.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
    
    const text = `Kavel: ${lotNumber} | ${dateStr} ${timeStr}`;
    
    // Premium styling: semi-transparent bar at the bottom
    // We make it proportional to the image height
    const barHeight = Math.round(h * 0.12); 
    const fontSize = Math.round(barHeight * 0.45);
    
    // 1. Background bar (semi-transparent dark)
    ctx.fillStyle = 'rgba(0, 0, 0, 0.45)';
    ctx.fillRect(0, h - barHeight, w, barHeight);
    
    // 2. Text styling
    ctx.fillStyle = 'rgba(255, 255, 255, 0.95)';
    ctx.font = `600 ${fontSize}px "Outfit", "Inter", -apple-system, sans-serif`;
    ctx.textBaseline = 'middle';
    ctx.textAlign = 'center'; // Center it for a modern look
    
    // Add subtle shadow for extra readability on bright backgrounds
    ctx.shadowColor = 'rgba(0,0,0,0.4)';
    ctx.shadowBlur = 3;
    ctx.shadowOffsetX = 1;
    ctx.shadowOffsetY = 1;
    
    ctx.fillText(text, w / 2, h - (barHeight / 2));
    
    // Reset shadow state
    ctx.shadowBlur = 0;
    ctx.shadowOffsetX = 0;
    ctx.shadowOffsetY = 0;
}

const meterNames = {
    water: 'Watermeter',
    gas: 'Gasmeter',
    elec: 'Elektrameter',
    test: 'Testfoto'
};

async function startMeterFlow(type) {
    currentMeterContext = type;
    document.getElementById('scan-meter-type').textContent = meterNames[type].toLowerCase();
    document.getElementById('verify-meter-name').textContent = meterNames[type];
    
    // Reset Validation UI
    document.getElementById('ocr-warning-box').style.display = 'none';
    document.getElementById('btn-confirm-meter').style.display = 'block';
    document.getElementById('btn-override-ocr').style.display = 'none';
    document.getElementById('manual-entry-box').style.display = 'none';
    
    isManualCorrectionActive = false;
    const input = document.getElementById('ocr-result');
    input.readOnly = true;
    input.style.background = "";
    input.style.border = "";

    // Add history state for hardware/browser back button
    history.pushState({ screen: 'photo-screen' }, 'Scanner', window.location.href);
    
    showScreen('photo-screen');

    // Slimme camera selectie: probeer eerst expliciet de achtercamera te vinden
    const backId = await findBackCameraId();
    await startCamera(backId);

    // Start de automatische stabiliteitscontrole
    startStabilityCheck();
}

function retakePhoto() {
    startMeterFlow(currentMeterContext);
}

async function confirmMeter() {
    const val = document.getElementById('ocr-result').value;
    const btnConfirm = document.querySelector('#verify-screen .btn-primary');
    const originalText = btnConfirm.textContent;
    
    if (currentMeterContext === 'test') {
        alert("Dit was een test. Resultaat was: " + val + ". Je wordt teruggestuurd naar de admin.");
        window.location.href = 'admin/';
        return;
    }

    // Preparation for Database storage
    const imageData = document.getElementById('verify-image').src;
    const lotId = 142; // Fallback for Demo, should be extracted from session

    btnConfirm.disabled = true;
    btnConfirm.textContent = "Opslaan...";

    try {
        const response = await fetch('../backend/api/meter_confirm.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                lot_id: lotId,
                type: currentMeterContext,
                reading: val,
                image: imageData,
                is_manual_correction: isManualCorrectionActive ? 1 : 0
            })
        });

        const data = await response.json();

        if (data.success) {
            // Update Local state only after success
            meterState[currentMeterContext].completed = true;
            meterState[currentMeterContext].val = val;
            
            // Update Success UI
            document.getElementById('success-meter-name').textContent = meterNames[currentMeterContext].toLowerCase();

            const incompleteMeters = Object.keys(meterState).filter(k => !meterState[k].completed);
            const nextMsg = document.getElementById('success-next-message');
            const btnNext = document.getElementById('btn-next-meter');
            
            if (incompleteMeters.length > 0) {
                const nextMeter = incompleteMeters[0];
                nextMsg.textContent = `Je hebt nog meterstanden open staan.`;
                document.getElementById('next-meter-type').textContent = meterNames[nextMeter].toLowerCase();
                btnNext.style.display = 'block';
                document.getElementById('incasso-section').style.display = 'none'; // Pas tonen als alles klaar is
            } else {
                nextMsg.textContent = 'Alle meterstanden zijn succesvol doorgegeven!';
                btnNext.style.display = 'none';

                // Check of we het incasso blok moeten tonen
                if (currentUser && currentUser.allow_direct_debit == 1 && !currentUser.incasso_mandate_date) {
                    document.getElementById('incasso-section').style.display = 'block';
                } else {
                    document.getElementById('incasso-section').style.display = 'none';
                }
            }
            
            showScreen('success-screen');
        } else {
            alert("Fout bij opslaan: " + (data.error || "Onbekende fout"));
        }
    } catch (error) {
        console.error("Confirm error:", error);
        alert("Netwerkfout bij het opslaan van de gegevens.");
    } finally {
        btnConfirm.disabled = false;
        btnConfirm.textContent = originalText;
    }
}

function startNextMeterFlow() {
    const incompleteMeters = Object.keys(meterState).filter(k => !meterState[k].completed);
    if (incompleteMeters.length > 0) {
        startMeterFlow(incompleteMeters[0]);
    } else {
        finishMeterFlow();
    }
}

function finishMeterFlow() {
    currentMeterContext = null;
    showDashboard(); // Will use cached user from DOM or we can just call updateDashboardUI
    updateDashboardUI();
    showScreen('dashboard-screen');
}

async function saveIncassoMandate() {
    const isChecked = document.getElementById('incasso-checkbox').checked;
    const ibanInput = document.getElementById('incasso-iban').value.replace(/\s+/g, '').toUpperCase();
    const txtFeedback = document.getElementById('incasso-feedback');
    
    // Basic IBAN validation (length and starting with letters)
    const ibanRegex = /^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/;

    if (!ibanRegex.test(ibanInput)) {
        txtFeedback.textContent = "Vul aub een geldig IBAN in.";
        txtFeedback.style.color = "#f87171";
        return;
    }

    if (!isChecked) {
        txtFeedback.textContent = "Je moet akkoord gaan door het vinkje aan te zetten.";
        txtFeedback.style.color = "#f87171";
        return;
    }

    const btn = document.getElementById('btn-save-incasso');
    btn.disabled = true;
    btn.textContent = "Bezig met opslaan...";
    txtFeedback.textContent = "";

    try {
        const response = await fetch('../backend/api/user.php?action=save-incasso-mandate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ agreed: true, iban: ibanInput })
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (currentUser) currentUser.incasso_mandate_date = data.mandate_date;
            
            txtFeedback.textContent = "Machtiging succesvol opgeslagen! Bedankt.";
            txtFeedback.style.color = "#4ade80";
            document.getElementById('incasso-checkbox').disabled = true;
            document.getElementById('incasso-iban').disabled = true;
            btn.style.display = 'none';
        } else {
            throw new Error(data.error || "Onbekende fout");
        }
    } catch (e) {
        txtFeedback.textContent = "Er ging iets mis: " + e.message;
        txtFeedback.style.color = "#f87171";
        btn.disabled = false;
        btn.textContent = "Machtiging Opslaan";
    }
}


