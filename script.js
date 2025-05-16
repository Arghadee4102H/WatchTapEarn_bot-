document.addEventListener('DOMContentLoaded', () => {
    const tg = window.Telegram.WebApp;
    tg.ready(); // Inform Telegram that the app is ready
    tg.expand(); // Expand the WebApp to full height

    const API_URL = 'api.php'; // Your backend API endpoint

    // Global state
    let currentUser = null;
    let currentEnergyInterval = null;
    let dailyTasks = [ // Hardcoded tasks
        { id: 1, title: "Join Telegram Channel 1", link: "https://t.me/Watchtapearn", reward: 50, type: "channel" },
        { id: 2, title: "Join Telegram Group", link: "https://t.me/Watchtapearnchat", reward: 50, type: "group" },
        { id: 3, title: "Join Telegram Channel 2", link: "https://t.me/earningsceret", reward: 50, type: "channel" },
        { id: 4, title: "Join Telegram Channel 3", link: "https://t.me/ShopEarnHub4102h", reward: 50, type: "channel" }
    ];
    let selectedWithdrawal = null;

    // UI Elements
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

    const tapPointsDisplay = document.getElementById('tap-points');
    const energyValueDisplay = document.getElementById('energy-value');
    const maxEnergyValueDisplay = document.getElementById('max-energy-value');
    const energyFill = document.getElementById('energy-fill');
    const catToTap = document.getElementById('cat-to-tap');
    const tapsTodayDisplay = document.getElementById('taps-today');
    const tapMessage = document.getElementById('tap-message');
    const catContainer = document.querySelector('.cat-container');


    const taskListUl = document.getElementById('task-list');
    const taskMessage = document.getElementById('task-message');

    const adsWatchedCountDisplay = document.getElementById('ads-watched-count');
    const watchAdButton = document.getElementById('watch-ad-button');
    const adStatusMessage = document.getElementById('ad-status-message');

    const withdrawPointsDisplay = document.getElementById('withdraw-points-display');
    const withdrawButtons = document.querySelectorAll('.withdraw-btn');
    const withdrawForm = document.getElementById('withdraw-form');
    const withdrawAmountText = document.getElementById('withdraw-amount-text');
    const paymentMethodSelect = document.getElementById('payment-method');
    const walletAddressInput = document.getElementById('wallet-address');
    const submitWithdrawalButton = document.getElementById('submit-withdrawal-button');
    const withdrawMessage = document.getElementById('withdraw-message');
    const withdrawalHistoryList = document.getElementById('withdrawal-history-list');


    // --- UTILITY FUNCTIONS ---
    function showLoader() { loader.style.display = 'flex'; }
    function hideLoader() { loader.style.display = 'none'; }

    function showPage(pageId) {
        pages.forEach(page => page.classList.add('hidden'));
        document.getElementById(pageId).classList.remove('hidden');
        navButtons.forEach(btn => btn.classList.remove('active'));
        document.querySelector(`.nav-btn[data-page="${pageId}"]`).classList.add('active');
        // Special updates when a page is shown
        if (pageId === 'withdraw-section') fetchWithdrawalHistory();
    }

    async function apiCall(action, data = {}) {
        showLoader();
        try {
            const tgUserData = tg.initDataUnsafe.user || {};
            const params = new URLSearchParams({
                action: action,
                telegram_user_id: tgUserData.id || 'test_user_123', // Fallback for local testing
                telegram_username: tgUserData.username || 'testuser',
                telegram_first_name: tgUserData.first_name || 'Test',
                ...data
            });

            // For POST requests if needed, but GET/URLSearchParams is fine for many actions
            const response = await fetch(API_URL, {
                method: 'POST', // Using POST for all actions for consistency
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            });
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ message: 'Network error' }));
                throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
            }
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'API request failed');
            }
            return result;
        } catch (error) {
            console.error(`API call error (${action}):`, error);
            showMessage(error.message || 'An unexpected error occurred.', 'error', 'tap-message'); // General message
            return null; // Or throw error to be caught by caller
        } finally {
            hideLoader();
        }
    }
    
    function showMessage(message, type = 'info', elementId = 'tap-message') {
        const msgEl = document.getElementById(elementId);
        if (!msgEl) return;
        msgEl.textContent = message;
        msgEl.className = `message ${type}`; // success, error, info
        setTimeout(() => {
            msgEl.textContent = '';
            msgEl.className = 'message';
        }, 3000);
    }


    // --- INITIALIZATION ---
    async function initializeApp() {
        if (!tg.initDataUnsafe || !tg.initDataUnsafe.user) {
            // For local testing without Telegram environment
            console.warn("Telegram UserData not found. Using mock data. Functionality may be limited.");
            // You might want to prompt for a test user ID or use a default
        }
        
        const startParam = tg.initDataUnsafe.start_param;
        let initialData = {};
        if (startParam) {
            initialData.referrer_id = startParam;
        }

        const data = await apiCall('getUserData', initialData);
        if (data && data.success) {
            currentUser = data.user;
            updateUI();
            startEnergyRegeneration();
        } else {
            showMessage('Failed to load user data. Please try again.', 'error', 'tap-message');
            // Potentially block app usage or show a retry button
        }
    }

    // --- UI UPDATE FUNCTIONS ---
    function updateUI() {
        if (!currentUser) return;

        // Global points update
        tapPointsDisplay.textContent = currentUser.points;
        profilePoints.textContent = currentUser.points;
        withdrawPointsDisplay.textContent = currentUser.points;

        // Profile
        profileUsername.textContent = currentUser.username || 'N/A';
        profileUserid.textContent = currentUser.user_id;
        profileJoindate.textContent = new Date(currentUser.join_date).toLocaleDateString();
        profileReferralLink.value = `https://t.me/WatchTapEarn_bot?start=${currentUser.user_id}`;
        profileReferralsCount.textContent = currentUser.referral_count;

        // Tap Section
        energyValueDisplay.textContent = Math.floor(currentUser.energy);
        maxEnergyValueDisplay.textContent = currentUser.max_energy;
        energyFill.style.width = `${(currentUser.energy / currentUser.max_energy) * 100}%`;
        tapsTodayDisplay.textContent = currentUser.taps_today;
        
        // Task Section
        renderTasks();

        // Ads Section
        adsWatchedCountDisplay.textContent = currentUser.ads_watched_today;
        checkAdButtonState();
    }

    // --- ENERGY REGENERATION ---
    function startEnergyRegeneration() {
        if (currentEnergyInterval) clearInterval(currentEnergyInterval);
        
        currentEnergyInterval = setInterval(() => {
            if (currentUser && currentUser.energy < currentUser.max_energy) {
                // Client-side optimistic update for smoother UI
                const energyToAdd = currentUser.energy_per_second; // This is per second
                currentUser.energy = Math.min(currentUser.max_energy, currentUser.energy + energyToAdd);
                energyValueDisplay.textContent = Math.floor(currentUser.energy);
                energyFill.style.width = `${(currentUser.energy / currentUser.max_energy) * 100}%`;
            } else if (currentUser && currentUser.energy >= currentUser.max_energy) {
                currentUser.energy = currentUser.max_energy; // Cap it
                energyValueDisplay.textContent = Math.floor(currentUser.energy);
                energyFill.style.width = `100%`;
            }
            // Server syncs energy on actual tap or other actions.
        }, 1000); // Update UI every second
    }


    // --- TAP SECTION LOGIC ---
    catToTap.addEventListener('click', async (event) => {
        if (!currentUser || currentUser.energy <= 0) {
            showMessage('Not enough energy!', 'error', 'tap-message');
            return;
        }
        if (currentUser.taps_today >= 2500) { // Max daily taps from constant
            showMessage('Daily tap limit reached!', 'error', 'tap-message');
            return;
        }

        // Optimistic UI update for tap
        currentUser.energy -= 1; // Assuming 1 energy per tap
        currentUser.points += 1; // Assuming 1 point per tap
        currentUser.taps_today += 1;
        updateUI(); // Quick update for energy and points

        // Show +1 animation
        const plusOne = document.createElement('div');
        plusOne.textContent = '+1';
        plusOne.classList.add('floating-plus-one');
        plusOne.style.left = `${event.clientX - catContainer.getBoundingClientRect().left - 10}px`;
        plusOne.style.top = `${event.clientY - catContainer.getBoundingClientRect().top - 20}px`;
        catContainer.appendChild(plusOne);
        setTimeout(() => plusOne.remove(), 1000);


        // Server call
        const result = await apiCall('tap');
        if (result && result.success) {
            currentUser = result.user; // Sync with server state
            updateUI(); // Full update
        } else {
            // Revert optimistic update if server call fails (or handle more gracefully)
            // For now, rely on next full update or getUserData call
            showMessage(result ? result.message : 'Tap failed to register.', 'error', 'tap-message');
        }
    });

    // --- TASK SECTION LOGIC ---
    function renderTasks() {
        if (!currentUser) return;
        taskListUl.innerHTML = ''; // Clear existing tasks
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
                </div>
            `;
            taskListUl.appendChild(li);
        });

        document.querySelectorAll('.claim-task-btn').forEach(button => {
            button.addEventListener('click', async (e) => {
                const taskId = parseInt(e.target.dataset.taskId);
                const task = dailyTasks.find(t => t.id === taskId);
                if (!task || (completedMask & (1 << (taskId - 1))) > 0) return;

                // Open link in Telegram
                tg.openTelegramLink(task.link);

                // After a short delay, try to claim. User needs to come back to app.
                // True verification is hard. This assumes they joined.
                // Show a temporary message
                showMessage('Redirecting to Telegram. Return here to confirm task completion if prompted, or it might auto-claim.', 'info', 'task-message');

                // For now, we'll claim it optimistically or prompt them to click again after returning.
                // Let's try to claim immediately. Backend will check daily limits.
                // A better UX might be a "Confirm Completion" button after they return.
                
                // Attempt to claim
                const result = await apiCall('claimTask', { task_id: taskId });
                if (result && result.success) {
                    currentUser = result.user;
                    updateUI();
                    showMessage(`Task "${task.title}" completed! +${task.reward} points.`, 'success', 'task-message');
                } else {
                    showMessage(result ? result.message : 'Failed to claim task.', 'error', 'task-message');
                }
            });
        });
    }


    // --- ADS SECTION LOGIC ---
    function checkAdButtonState() {
        if (!currentUser) return;
        const adsLimitReached = currentUser.ads_watched_today >= 45; // Max daily ads from constant
        const cooldownActive = currentUser.last_ad_watched_timestamp && 
                               (new Date() - new Date(currentUser.last_ad_watched_timestamp)) < (3 * 60 * 1000); // 3 min cooldown

        if (adsLimitReached) {
            watchAdButton.disabled = true;
            adStatusMessage.textContent = 'Daily ad limit reached.';
        } else if (cooldownActive) {
            watchAdButton.disabled = true;
            const remainingCooldown = Math.ceil(((3*60*1000) - (new Date() - new Date(currentUser.last_ad_watched_timestamp))) / 1000);
            adStatusMessage.textContent = `Next ad available in ${remainingCooldown}s.`;
            // Optional: set a timeout to re-enable the button
            setTimeout(checkAdButtonState, 1000); 
        } else {
            watchAdButton.disabled = false;
            adStatusMessage.textContent = 'Watch an ad to earn points.';
        }
    }

    watchAdButton.addEventListener('click', () => {
        if (watchAdButton.disabled) return;

        adStatusMessage.textContent = 'Loading ad...';
        watchAdButton.disabled = true;

        // Using Monetag Rewarded Interstitial as an example
        // The function `show_9338274` is from Monetag's SDK
        if (typeof show_9338274 === 'function') {
            show_9338274() // For Rewarded Interstitial. For Popup: show_9338274('pop')
                .then(async () => {
                    // User watched the ad
                    adStatusMessage.textContent = 'Ad completed! Claiming reward...';
                    const result = await apiCall('watchAd');
                    if (result && result.success) {
                        currentUser = result.user;
                        updateUI(); // Update points and ad count
                        showMessage(`+${result.points_earned || 40} points for watching ad!`, 'success', 'ad-status-message');
                    } else {
                        showMessage(result ? result.message : 'Failed to claim ad reward.', 'error', 'ad-status-message');
                    }
                    checkAdButtonState(); // Re-check button state (cooldown, limits)
                })
                .catch(e => {
                    // Error during ad playback or user closed it early (for some ad types)
                    console.error('Monetag ad error:', e);
                    showMessage('Ad could not be shown or was closed early. No reward.', 'error', 'ad-status-message');
                    checkAdButtonState(); // Re-check button state
                });
        } else {
            showMessage('Ad SDK not available. Please try again later.', 'error', 'ad-status-message');
            checkAdButtonState();
        }
    });


    // --- WITHDRAW SECTION LOGIC ---
    withdrawButtons.forEach(button => {
        button.addEventListener('click', () => {
            const pointsNeeded = parseInt(button.dataset.points);
            if (currentUser.points < pointsNeeded) {
                showMessage(`Not enough points. You need ${pointsNeeded}.`, 'error', 'withdraw-message');
                return;
            }
            selectedWithdrawal = {
                points: pointsNeeded,
                usd: parseInt(button.dataset.usd)
            };
            withdrawAmountText.textContent = `${pointsNeeded.toLocaleString()}`;
            withdrawForm.classList.remove('hidden');
            walletAddressInput.value = ''; // Clear previous input
            withdrawMessage.textContent = '';
        });
    });

    submitWithdrawalButton.addEventListener('click', async () => {
        if (!selectedWithdrawal) return;

        const method = paymentMethodSelect.value;
        const addressOrId = walletAddressInput.value.trim();

        if (!addressOrId) {
            showMessage('Please enter your wallet address or Pay ID.', 'error', 'withdraw-message');
            return;
        }

        const result = await apiCall('requestWithdrawal', {
            points_to_withdraw: selectedWithdrawal.points,
            method: method,
            wallet_address_or_id: addressOrId
        });

        if (result && result.success) {
            currentUser = result.user; // Update user data (points deducted)
            updateUI();
            showMessage(`Withdrawal request for ${selectedWithdrawal.points} points submitted! Status: PENDING.`, 'success', 'withdraw-message');
            withdrawForm.classList.add('hidden');
            selectedWithdrawal = null;
            fetchWithdrawalHistory(); // Refresh history
        } else {
            showMessage(result ? result.message : 'Withdrawal request failed.', 'error', 'withdraw-message');
        }
    });

    async function fetchWithdrawalHistory() {
        const result = await apiCall('getWithdrawalHistory');
        withdrawalHistoryList.innerHTML = ''; // Clear previous history
        if (result && result.success && result.history) {
            if (result.history.length === 0) {
                withdrawalHistoryList.innerHTML = '<li>No withdrawal history.</li>';
            } else {
                result.history.forEach(item => {
                    const li = document.createElement('li');
                    li.textContent = `${new Date(item.requested_at).toLocaleString()}: ${item.points_withdrawn} points to ${item.method} (${item.wallet_address_or_id.substring(0,10)}...) - Status: ${item.status}`;
                    withdrawalHistoryList.appendChild(li);
                });
            }
        } else {
            withdrawalHistoryList.innerHTML = '<li>Could not load history.</li>';
        }
    }
    
    // --- NAVIGATION ---
    navButtons.forEach(button => {
        button.addEventListener('click', () => {
            showPage(button.dataset.page);
        });
    });

    // --- REFERRAL LINK COPY ---
    copyReferralLinkBtn.addEventListener('click', () => {
        profileReferralLink.select();
        document.execCommand('copy');
        // tg.HapticFeedback.notificationOccurred('success'); // If available
        showMessage('Referral link copied!', 'success', 'profile-username'); // Use any element for message
    });


    // --- MONETAG IN-APP INTERSTITIAL (Example, if needed) ---
    // This is the auto-showing interstitial. You might not need this if you only use rewarded.
    // if (typeof show_9338274 === 'function') {
    //     show_9338274({
    //         type: 'inApp',
    //         inAppSettings: {
    //             frequency: 2,     // Show automatically 2 ads
    //             capping: 0.1,     // within 0.1 hours (6 minutes)
    //             interval: 30,     // with a 30-second interval between them
    //             timeout: 5,       // and a 5-second delay before the first one
    //             everyPage: false  // 0 = session saved on navigation
    //         }
    //     });
    // }

    // Start the app
    initializeApp();
});
