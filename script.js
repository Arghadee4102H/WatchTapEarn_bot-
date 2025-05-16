document.addEventListener('DOMContentLoaded', () => {
    const tg = window.Telegram.WebApp;
    tg.ready();
    tg.expand();

    const API_URL = 'api.php'; // Ensure this path is correct relative to index.html

    let currentUser = null;
    let currentEnergyInterval = null;
    const dailyTasks = [ // Ensure these links are correct
        { id: 1, title: "Join Telegram Channel 1", link: "https://t.me/Watchtapearn", reward: 50, type: "channel" },
        { id: 2, title: "Join Telegram Group", link: "https://t.me/Watchtapearnchat", reward: 50, type: "group" },
        { id: 3, title: "Join Telegram Channel 2", link: "https://t.me/earningsceret", reward: 50, type: "channel" },
        { id: 4, title: "Join Telegram Channel 3", link: "https://t.me/ShopEarnHub4102h", reward: 50, type: "channel" }
    ];
    let selectedWithdrawal = null;

    // UI Elements (ensure all IDs match HTML)
    const loader = document.getElementById('loader');
    const pages = document.querySelectorAll('.page');
    const navButtons = document.querySelectorAll('.nav-btn');

    const profileUsername = document.getElementById('profile-username');
    const profileUserid = document.getElementById('profile-userid');
    const profilePoints = document.getElementById('profile-points');
    const profileJoindate = document.getElementById('profile-joindate');
    const profileReferralLink = document.getElementById('profile-referral-link');
    const copyReferralLinkBtn = document.getElementById('copy-referral-link');
    const profileReferralsCount = document.getElementById('profile-referrals-count');
    const profileMessage = document.getElementById('profile-message');

    const tapPointsDisplay = document.getElementById('tap-points');
    const energyValueDisplay = document.getElementById('energy-value');
    const maxEnergyValueDisplay = document.getElementById('max-energy-value');
    const energyFill = document.getElementById('energy-fill');
    const animalToTap = document.getElementById('animal-to-tap');
    const tapsTodayDisplay = document.getElementById('taps-today');
    const tapMessage = document.getElementById('tap-message');
    const animalContainer = document.querySelector('.cat-container'); // Assuming .cat-container still wraps the image

    const taskListUl = document.getElementById('task-list');
    const taskMessage = document.getElementById('task-message');

    const adsWatchedCountDisplay = document.getElementById('ads-watched-count');
    const watchAdButton = document.getElementById('watch-ad-button');
    const adStatusMessage = document.getElementById('ad-status-message');
    document.getElementById('points-per-ad-info').textContent = '40'; // Update from constant if changed

    const withdrawPointsDisplay = document.getElementById('withdraw-points-display');
    const withdrawButtons = document.querySelectorAll('.withdraw-btn');
    const withdrawForm = document.getElementById('withdraw-form');
    const withdrawAmountText = document.getElementById('withdraw-amount-text');
    const paymentMethodSelect = document.getElementById('payment-method');
    const walletAddressInput = document.getElementById('wallet-address');
    const submitWithdrawalButton = document.getElementById('submit-withdrawal-button');
    const withdrawMessage = document.getElementById('withdraw-message');
    const withdrawalHistoryList = document.getElementById('withdrawal-history-list');

    function showLoader() { if(loader) loader.style.display = 'flex'; }
    function hideLoader() { if(loader) loader.style.display = 'none'; }

    function showPage(pageId) {
        pages.forEach(page => page.classList.add('hidden'));
        const targetPage = document.getElementById(pageId);
        if (targetPage) targetPage.classList.remove('hidden');
        
        navButtons.forEach(btn => btn.classList.remove('active'));
        const activeBtn = document.querySelector(`.nav-btn[data-page="${pageId}"]`);
        if (activeBtn) activeBtn.classList.add('active');
        
        clearAllMessages();

        if (currentUser) { // Only run these if currentUser is loaded
            if (pageId === 'withdraw-section') fetchWithdrawalHistory();
            if (pageId === 'task-section') renderTasks();
            if (pageId === 'ads-section') checkAdButtonState();
        }
    }
    
    function clearAllMessages() {
        document.querySelectorAll('.message').forEach(el => {
            el.textContent = '';
            el.className = 'message';
        });
    }

    async function apiCall(action, data = {}, method = 'POST') {
        showLoader();
        try {
            const tgUserData = tg.initDataUnsafe.user || {};
            // For local testing, generate a somewhat persistent debug user ID
            let debugUserId = localStorage.getItem('tapSwapDebugUserId');
            if (!debugUserId) {
                debugUserId = 'debug_' + Date.now().toString().slice(-8);
                localStorage.setItem('tapSwapDebugUserId', debugUserId);
            }

            const params = new URLSearchParams({
                action: action,
                telegram_user_id: tgUserData.id || debugUserId, // Use debug ID if no TG user
                telegram_username: tgUserData.username || `test_${debugUserId.split('_')[1]}`,
                telegram_first_name: tgUserData.first_name || 'Test',
                telegram_init_data: tg.initData || '', // For server-side validation (important!)
                ...data
            });
            
            console.log(`API Call (${action}):`, Object.fromEntries(params)); // Log params

            const response = await fetch(API_URL, {
                method: method,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            });

            const responseText = await response.text(); // Get raw text first for debugging
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (e) {
                console.error(`API Call (${action}) - Failed to parse JSON response:`, responseText);
                console.error("Parse error object:", e);
                // Show the raw response to the user for debugging if it's short, or a generic error
                let errorMsg = "Invalid server response. Check console & server logs.";
                if (responseText.length < 200) { // Heuristic for short error messages from server
                   // errorMsg += ` Response: ${responseText}`; // Be careful showing raw response
                }
                throw new Error(errorMsg);
            }
            
            console.log(`API Response (${action}):`, result);

            if (!response.ok) { // Check HTTP status code
                 throw new Error(result.message || `API request failed with status ${response.status} (${action})`);
            }
            if (!result.success) { // Check success flag from our API
                throw new Error(result.message || `API returned failure (${action})`);
            }
            return result;
        } catch (error) {
            console.error(`API Call General Error (${action}):`, error);
            // Display error on the current page's message area
            showMessage(error.message || 'An unexpected error occurred. Please try again.', 'error');
            tg.HapticFeedback.notificationOccurred('error');
            return null; // Indicate failure
        } finally {
            hideLoader();
        }
    }
    
    function showMessage(message, type = 'info', elementIdSuffix = null) {
        let targetElementId;
        if (elementIdSuffix) { // Allow direct specification
            targetElementId = elementIdSuffix;
        } else {
            const currentPageElement = document.querySelector('.page:not(.hidden)');
            const currentPageId = currentPageElement ? currentPageElement.id : null;
            switch (currentPageId) {
                case 'profile-section': targetElementId = 'profile-message'; break;
                case 'task-section': targetElementId = 'task-message'; break;
                case 'ads-section': targetElementId = 'ad-status-message'; break;
                case 'withdraw-section': targetElementId = 'withdraw-message'; break;
                case 'tap-section': // Fallthrough to default
                default: targetElementId = 'tap-message'; break;
            }
        }
        
        const msgEl = document.getElementById(targetElementId);
        if (msgEl) {
            msgEl.textContent = message;
            msgEl.className = `message ${type}`;
            if (type !== 'error' && type !== 'info') { // Auto-clear success messages
                setTimeout(() => {
                    if (msgEl.textContent === message) {
                        msgEl.textContent = '';
                        msgEl.className = 'message';
                    }
                }, 3500);
            }
        } else {
            console.warn("Message element not found for ID:", targetElementId, "Original message:", message);
        }
    }

    async function initializeApp() {
        console.log("Initializing App...");
        if (!tg.initDataUnsafe || !tg.initDataUnsafe.user) {
            console.warn("Telegram UserData not found. Using mock/debug data. This is for local testing only.");
        }
        
        const startParam = tg.initDataUnsafe.start_param;
        let initialData = {};
        if (startParam) {
            initialData.referrer_id = startParam;
            console.log("Referrer ID from start_param:", startParam);
        }

        const data = await apiCall('getUserData', initialData);
        if (data && data.success && data.user) {
            currentUser = data.user;
            console.log("User data loaded successfully:", currentUser);
            updateUI();
            startEnergyRegeneration();
            showPage('tap-section'); // Default page after successful load
        } else {
            // This is the error message the user is seeing.
            // The apiCall function itself should have displayed a more specific error if possible.
            // If it reaches here, it means apiCall returned null or a response without data.success/data.user.
            console.error("Critical: Failed to initialize user data from API.", data);
            showMessage('Failed to load user data. Please restart the app.', 'error', 'tap-message');
            // Optionally, you could disable parts of the UI here.
        }
    }

    function updateUI() {
        if (!currentUser) {
            console.warn("updateUI called but currentUser is null.");
            return;
        }
        console.log("Updating UI with user:", currentUser);

        // Ensure points are formatted nicely if they are large strings from bcmath
        const formattedPoints = currentUser.points ? Number(currentUser.points).toLocaleString() : '0';

        tapPointsDisplay.textContent = formattedPoints;
        profilePoints.textContent = formattedPoints;
        withdrawPointsDisplay.textContent = formattedPoints;

        profileUsername.textContent = currentUser.username || 'N/A';
        profileUserid.textContent = currentUser.user_id;
        profileJoindate.textContent = currentUser.join_date ? new Date(currentUser.join_date + 'Z').toLocaleDateString() : 'N/A'; // Assuming UTC from DB
        profileReferralLink.value = `https://t.me/WatchTapEarn_bot?start=${currentUser.user_id}`; // Ensure YOUR_BOT_USERNAME is correct
        profileReferralsCount.textContent = currentUser.referral_count || '0';

        energyValueDisplay.textContent = Math.floor(currentUser.energy);
        maxEnergyValueDisplay.textContent = currentUser.max_energy;
        const energyPercentage = currentUser.max_energy > 0 ? (currentUser.energy / currentUser.max_energy) * 100 : 0;
        energyFill.style.width = `${Math.max(0, Math.min(100, energyPercentage))}%`;
        
        tapsTodayDisplay.textContent = currentUser.taps_today || '0';
        
        renderTasks(); 
        checkAdButtonState();
    }

    function startEnergyRegeneration() {
        if (currentEnergyInterval) clearInterval(currentEnergyInterval);
        if (!currentUser) return;

        currentEnergyInterval = setInterval(() => {
            if (currentUser && currentUser.energy < currentUser.max_energy) {
                const energyToAdd = parseFloat(currentUser.energy_per_second) || 0.1; // Ensure energy_per_second is a number
                currentUser.energy = Math.min(currentUser.max_energy, currentUser.energy + energyToAdd);
                
                energyValueDisplay.textContent = Math.floor(currentUser.energy);
                const energyPercentage = currentUser.max_energy > 0 ? (currentUser.energy / currentUser.max_energy) * 100 : 0;
                energyFill.style.width = `${Math.max(0, Math.min(100, energyPercentage))}%`;
            } else if (currentUser && currentUser.energy >= currentUser.max_energy) {
                currentUser.energy = currentUser.max_energy; // Cap it
                energyValueDisplay.textContent = Math.floor(currentUser.energy);
                energyFill.style.width = `100%`;
            }
        }, 1000);
    }

    animalToTap.addEventListener('click', async (event) => {
        if (!currentUser) {
            showMessage('User data not loaded yet.', 'error', 'tap-message'); return;
        }
        // Use constants for energy per tap and points per tap if defined, otherwise defaults
        const energyCostPerTap = 1; 
        const pointsPerTap = 1;

        if (currentUser.energy < energyCostPerTap) {
            showMessage('Not enough energy!', 'error', 'tap-message');
            tg.HapticFeedback.notificationOccurred('error'); return;
        }
        if (currentUser.taps_today >= 2500) { // MAX_DAILY_TAPS
            showMessage('Daily tap limit reached!', 'error', 'tap-message');
            tg.HapticFeedback.notificationOccurred('error'); return;
        }

        // Optimistic UI update
        currentUser.energy -= energyCostPerTap; 
        currentUser.points = (BigInt(currentUser.points || '0') + BigInt(pointsPerTap)).toString();
        currentUser.taps_today = (currentUser.taps_today || 0) + 1;
        updateUI(); 

        const plusOne = document.createElement('div');
        plusOne.textContent = `+${pointsPerTap}`;
        plusOne.classList.add('floating-plus-one');
        const containerRect = animalContainer.getBoundingClientRect();
        plusOne.style.left = `${event.clientX - containerRect.left - (plusOne.offsetWidth / 2)}px`;
        plusOne.style.top = `${event.clientY - containerRect.top - 30}px`; // Adjust offset
        animalContainer.appendChild(plusOne);
        tg.HapticFeedback.impactOccurred('light');
        setTimeout(() => plusOne.remove(), 1000);

        const result = await apiCall('tap');
        if (result && result.success && result.user) {
            currentUser = result.user; // Sync with server state
            updateUI();
        } else {
            // Tap failed on server, revert or show error and wait for next sync
            // For now, message is shown by apiCall, and next full update will correct UI.
            // To be perfectly accurate, you might refetch user data immediately on failure.
            console.warn("Tap API call failed or returned unexpected data.");
        }
    });

    function renderTasks() {
        if (!currentUser || !taskListUl) return;
        taskListUl.innerHTML = '';
        const completedMask = currentUser.daily_tasks_completed_mask || 0;

        dailyTasks.forEach(task => {
            const li = document.createElement('li');
            const isCompleted = (completedMask & (1 << (task.id - 1))) > 0;
            
            li.className = isCompleted ? 'completed' : '';
            li.innerHTML = `
                <div>
                    <span class="task-title">${task.title}</span><br>
                    <span class="task-reward">Reward: ${task.reward} Points</span>
                </div>
                <div class="task-action">
                    <button class="claim-task-btn" data-task-id="${task.id}" ${isCompleted ? 'disabled' : ''}>
                        ${isCompleted ? 'Completed' : 'Join & Claim'}
                    </button>
                </div>`;
            taskListUl.appendChild(li);
        });

        document.querySelectorAll('.claim-task-btn').forEach(button => {
            button.addEventListener('click', async (e) => {
                const claimButton = e.target;
                if (claimButton.disabled) return;

                const taskId = parseInt(claimButton.dataset.taskId);
                const task = dailyTasks.find(t => t.id === taskId);
                if (!task) return;

                claimButton.disabled = true;
                claimButton.textContent = 'Processing...';
                
                tg.openTelegramLink(task.link);
                showMessage(`Opening ${task.title}... Return to app to finalize.`, 'info', 'task-message');
                
                // Adding a slight delay to give user time to potentially join.
                // A "Verify Completion" button clicked by user upon return is more robust.
                setTimeout(async () => {
                    // Re-check if task got completed by another means or if button still valid
                     if (!currentUser || ((currentUser.daily_tasks_completed_mask || 0) & (1 << (taskId - 1))) > 0) {
                        if (claimButton.parentNode) claimButton.textContent = 'Completed'; // Update if still on page
                        renderTasks(); // Re-render to ensure UI is fresh
                        return;
                    }

                    const result = await apiCall('claimTask', { task_id: taskId });
                    if (result && result.success && result.user) {
                        currentUser = result.user;
                        updateUI(); // Will re-render tasks
                        showMessage(`Task "${task.title}" completed! +${result.points_earned || task.reward} points.`, 'success', 'task-message');
                        tg.HapticFeedback.notificationOccurred('success');
                    } else {
                        showMessage(result ? result.message : 'Failed to claim task. Ensure you joined and try again.', 'error', 'task-message');
                        // Re-enable button if it's still relevant and not completed
                        if (!((currentUser.daily_tasks_completed__mask || 0) & (1 << (taskId - 1)))) {
                             claimButton.disabled = false;
                             claimButton.textContent = 'Join & Claim';
                        } else {
                            renderTasks(); // Task might have been completed by another action or state changed
                        }
                    }
                }, 3000); // 3-second delay, adjust as needed
            });
        });
    }

    function checkAdButtonState() {
        if (!currentUser || !watchAdButton) return;
        const adsLimitReached = currentUser.ads_watched_today >= 45; // MAX_DAILY_ADS
        let cooldownActive = false;
        let remainingCooldown = 0;

        if (currentUser.last_ad_watched_timestamp) {
            const lastAdTime = new Date(currentUser.last_ad_watched_timestamp + 'Z').getTime(); // Assume UTC
            const now = new Date().getTime();
            const cooldownMillis = (3 * 60 * 1000); // AD_COOLDOWN_SECONDS
            if (now - lastAdTime < cooldownMillis) {
                cooldownActive = true;
                remainingCooldown = Math.ceil((cooldownMillis - (now - lastAdTime)) / 1000);
            }
        }

        if (adsLimitReached) {
            watchAdButton.disabled = true;
            adStatusMessage.textContent = 'Daily ad limit reached.';
        } else if (cooldownActive) {
            watchAdButton.disabled = true;
            adStatusMessage.textContent = `Next ad available in ${remainingCooldown}s.`;
            setTimeout(checkAdButtonState, 1000); 
        } else {
            watchAdButton.disabled = false;
            adStatusMessage.textContent = 'Watch an ad to earn points.';
        }
    }

    watchAdButton.addEventListener('click', () => {
        if (watchAdButton.disabled || !currentUser) return;

        adStatusMessage.textContent = 'Loading ad...';
        watchAdButton.disabled = true;

        if (typeof show_9338274 === 'function') { // Monetag function
            show_9338274() 
                .then(async () => {
                    adStatusMessage.textContent = 'Ad completed! Claiming reward...';
                    const result = await apiCall('watchAd');
                    if (result && result.success && result.user) {
                        currentUser = result.user;
                        updateUI();
                        showMessage(`+${result.points_earned || '40'} points!`, 'success', 'ad-status-message');
                        tg.HapticFeedback.notificationOccurred('success');
                    } else {
                        // message shown by apiCall
                        updateUI(); // Still update UI to reflect potentially unchanged state from server
                    }
                })
                .catch(e => {
                    console.error('Monetag ad error:', e);
                    showMessage('Ad error or closed early. No reward.', 'error', 'ad-status-message');
                    checkAdButtonState(); // Re-check button state as UI wasn't updated via successful API call
                });
        } else {
            showMessage('Ad SDK not available.', 'error', 'ad-status-message');
            checkAdButtonState();
        }
    });

    withdrawButtons.forEach(button => {
        button.addEventListener('click', () => {
            if (!currentUser) return;
            const pointsNeeded = BigInt(button.dataset.points);
            if (BigInt(currentUser.points) < pointsNeeded) {
                showMessage(`Not enough points. You need ${pointsNeeded.toLocaleString()}.`, 'error', 'withdraw-message');
                tg.HapticFeedback.notificationOccurred('error');
                return;
            }
            selectedWithdrawal = {
                points: pointsNeeded.toString(), // Store as string for API
                usd: parseInt(button.dataset.usd)
            };
            withdrawAmountText.textContent = `${pointsNeeded.toLocaleString()}`;
            withdrawForm.classList.remove('hidden');
            walletAddressInput.value = '';
            withdrawMessage.textContent = '';
        });
    });

    submitWithdrawalButton.addEventListener('click', async () => {
        if (!selectedWithdrawal || !currentUser) return;

        const method = paymentMethodSelect.value;
        const addressOrId = walletAddressInput.value.trim();

        if (!addressOrId) {
            showMessage('Please enter your wallet address or Pay ID.', 'error', 'withdraw-message');
            return;
        }
        
        submitWithdrawalButton.disabled = true;
        submitWithdrawalButton.textContent = 'Processing...';

        const result = await apiCall('requestWithdrawal', {
            points_to_withdraw: selectedWithdrawal.points, // Send as string
            method: method,
            wallet_address_or_id: addressOrId
        });

        if (result && result.success && result.user) {
            currentUser = result.user;
            updateUI();
            showMessage(`Withdrawal request for ${BigInt(selectedWithdrawal.points).toLocaleString()} points submitted!`, 'success', 'withdraw-message');
            tg.HapticFeedback.notificationOccurred('success');
            withdrawForm.classList.add('hidden');
            selectedWithdrawal = null;
            fetchWithdrawalHistory();
        } else {
            // message handled by apiCall
        }
        submitWithdrawalButton.disabled = false;
        submitWithdrawalButton.textContent = 'Submit Withdrawal';
    });

    async function fetchWithdrawalHistory() {
        if (!currentUser) return;
        withdrawalHistoryList.innerHTML = '<li>Loading history...</li>';
        const result = await apiCall('getWithdrawalHistory');
        withdrawalHistoryList.innerHTML = ''; // Clear
        if (result && result.success && result.history) {
            if (result.history.length === 0) {
                withdrawalHistoryList.innerHTML = '<li>No withdrawal history.</li>';
            } else {
                result.history.forEach(item => {
                    const li = document.createElement('li');
                    const pointsDisplay = item.points_withdrawn ? BigInt(item.points_withdrawn).toLocaleString() : 'N/A';
                    const dateDisplay = item.requested_at ? new Date(item.requested_at + 'Z').toLocaleString() : 'N/A'; // Assume UTC
                    li.textContent = `${dateDisplay}: ${pointsDisplay} points to ${item.method} (...) - Status: ${item.status}`;
                    withdrawalHistoryList.appendChild(li);
                });
            }
        } else {
            withdrawalHistoryList.innerHTML = '<li>Could not load history.</li>';
        }
    }
    
    navButtons.forEach(button => {
        button.addEventListener('click', () => {
            showPage(button.dataset.page);
        });
    });

    copyReferralLinkBtn.addEventListener('click', () => {
        if (!profileReferralLink.value) return;
        profileReferralLink.select();
        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showMessage('Referral link copied!', 'success', 'profile-message');
                tg.HapticFeedback.notificationOccurred('success');
            } else {
                throw new Error('Copy command failed');
            }
        } catch (err) {
            showMessage('Failed to copy. Please copy manually.', 'error', 'profile-message');
            console.error('Copy failed:', err);
        }
        window.getSelection().removeAllRanges();
    });

    // --- App Initialization ---
    initializeApp();
});
