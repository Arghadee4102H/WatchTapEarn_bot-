document.addEventListener('DOMContentLoaded', () => {
    const tg = window.Telegram.WebApp;
    tg.ready();
    tg.expand();

    const API_URL = 'api.php';

    let currentUser = null;
    let currentEnergyInterval = null;
    const dailyTasks = [
        { id: 1, title: "Join Telegram Channel 1", link: "https://t.me/Watchtapearn", reward: 50, type: "channel" },
        { id: 2, title: "Join Telegram Group", link: "https://t.me/Watchtapearnchat", reward: 50, type: "group" },
        { id: 3, title: "Join Telegram Channel 2", link: "https://t.me/earningsceret", reward: 50, type: "channel" },
        { id: 4, title: "Join Telegram Channel 3", link: "https://t.me/ShopEarnHub4102h", reward: 50, type: "channel" }
    ];
    let selectedWithdrawal = null;

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
    const animalToTap = document.getElementById('animal-to-tap'); // Changed ID
    const tapsTodayDisplay = document.getElementById('taps-today');
    const tapMessage = document.getElementById('tap-message');
    const animalContainer = document.querySelector('.cat-container'); // Changed class if needed

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

    function showLoader() { loader.style.display = 'flex'; }
    function hideLoader() { loader.style.display = 'none'; }

    function showPage(pageId) {
        pages.forEach(page => page.classList.add('hidden'));
        document.getElementById(pageId).classList.remove('hidden');
        navButtons.forEach(btn => btn.classList.remove('active'));
        const activeBtn = document.querySelector(`.nav-btn[data-page="${pageId}"]`);
        if (activeBtn) activeBtn.classList.add('active');
        
        // Clear messages when switching pages
        clearAllMessages();

        if (pageId === 'withdraw-section' && currentUser) fetchWithdrawalHistory();
        if (pageId === 'task-section' && currentUser) renderTasks(); // Re-render tasks to get latest status
        if (pageId === 'ads-section' && currentUser) checkAdButtonState(); // Update ad button state
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
             const defaultUserId = localStorage.getItem('debugUserId') || Date.now().toString().slice(-6); // More unique debug ID
            localStorage.setItem('debugUserId', defaultUserId);

            const params = new URLSearchParams({
                action: action,
                telegram_user_id: tgUserData.id || defaultUserId,
                telegram_username: tgUserData.username || `testuser_${defaultUserId}`,
                telegram_first_name: tgUserData.first_name || 'Test',
                telegram_init_data: tg.initData || '', // Send full initData for server-side validation
                ...data
            });
            
            const response = await fetch(API_URL, {
                method: method,
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            });

            const resultText = await response.text();
            let result;
            try {
                result = JSON.parse(resultText);
            } catch (e) {
                console.error("Failed to parse JSON response:", resultText);
                throw new Error("Invalid server response. Please check server logs.");
            }
            
            if (!response.ok || !result.success) {
                throw new Error(result.message || `API request failed (${action})`);
            }
            return result;
        } catch (error) {
            console.error(`API call error (${action}):`, error);
            showMessage(error.message || 'An unexpected error occurred.', 'error', 'tap-message'); // General message area
            return null;
        } finally {
            hideLoader();
        }
    }
    
    function showMessage(message, type = 'info', elementIdSuffix = 'message') {
        let targetElementId = 'tap-message'; // Default
        const currentPageId = document.querySelector('.page:not(.hidden)')?.id;

        if (currentPageId) {
            if (currentPageId === 'profile-section') targetElementId = 'profile-message';
            else if (currentPageId === 'task-section') targetElementId = 'task-message';
            else if (currentPageId === 'ads-section') targetElementId = 'ad-status-message';
            else if (currentPageId === 'withdraw-section') targetElementId = 'withdraw-message';
        }
        
        const msgEl = document.getElementById(targetElementId);
        if (!msgEl) {
            console.warn("Message element not found for ID:", targetElementId);
            return;
        }
        msgEl.textContent = message;
        msgEl.className = `message ${type}`;
        
        // Auto-clear message after some time, unless it's an error that needs attention
        if (type !== 'error') {
            setTimeout(() => {
                if (msgEl.textContent === message) { // Clear only if it's the same message
                    msgEl.textContent = '';
                    msgEl.className = 'message';
                }
            }, 3500);
        }
    }

    async function initializeApp() {
        if (!tg.initDataUnsafe || !tg.initDataUnsafe.user) {
            console.warn("Telegram UserData not found. Using mock data. Ensure you test in Telegram environment.");
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
            updateUI();
            startEnergyRegeneration();
            showPage('tap-section'); // Default page
        } else {
            showMessage('Failed to load user data. Please restart the app.', 'error');
        }
    }

    function updateUI() {
        if (!currentUser) return;

        tapPointsDisplay.textContent = currentUser.points.toLocaleString();
        profilePoints.textContent = currentUser.points.toLocaleString();
        withdrawPointsDisplay.textContent = currentUser.points.toLocaleString();

        profileUsername.textContent = currentUser.username || 'N/A';
        profileUserid.textContent = currentUser.user_id;
        profileJoindate.textContent = currentUser.join_date ? new Date(currentUser.join_date).toLocaleDateString() : 'N/A';
        profileReferralLink.value = `https://t.me/WatchTapEarn_bot?start=${currentUser.user_id}`; // Replace YOUR_BOT_USERNAME
        profileReferralsCount.textContent = currentUser.referral_count;

        energyValueDisplay.textContent = Math.floor(currentUser.energy);
        maxEnergyValueDisplay.textContent = currentUser.max_energy;
        const energyPercentage = currentUser.max_energy > 0 ? (currentUser.energy / currentUser.max_energy) * 100 : 0;
        energyFill.style.width = `${Math.max(0, Math.min(100, energyPercentage))}%`;
        tapsTodayDisplay.textContent = currentUser.taps_today;
        
        renderTasks(); // Update task list as user data might have changed task completion status
        checkAdButtonState(); // Update ad button state
    }

    function startEnergyRegeneration() {
        if (currentEnergyInterval) clearInterval(currentEnergyInterval);
        
        currentEnergyInterval = setInterval(() => {
            if (currentUser && currentUser.energy < currentUser.max_energy) {
                const energyToAdd = currentUser.energy_per_second;
                currentUser.energy = Math.min(currentUser.max_energy, currentUser.energy + energyToAdd);
                
                // Only update display, server handles true energy
                energyValueDisplay.textContent = Math.floor(currentUser.energy);
                const energyPercentage = currentUser.max_energy > 0 ? (currentUser.energy / currentUser.max_energy) * 100 : 0;
                energyFill.style.width = `${Math.max(0, Math.min(100, energyPercentage))}%`;
            } else if (currentUser && currentUser.energy >= currentUser.max_energy) {
                currentUser.energy = currentUser.max_energy;
                energyValueDisplay.textContent = Math.floor(currentUser.energy);
                energyFill.style.width = `100%`;
            }
        }, 1000);
    }

    animalToTap.addEventListener('click', async (event) => {
        if (!currentUser) {
            showMessage('User data not loaded. Please wait.', 'error', 'tap-message');
            return;
        }
        if (currentUser.energy < 1) { // Assuming 1 energy per tap from ENERGY_PER_TAP constant
            showMessage('Not enough energy!', 'error', 'tap-message');
            tg.HapticFeedback.notificationOccurred('error');
            return;
        }
        if (currentUser.taps_today >= 2500) {
            showMessage('Daily tap limit reached!', 'error', 'tap-message');
            tg.HapticFeedback.notificationOccurred('error');
            return;
        }

        currentUser.energy -= 1; 
        currentUser.points += 1; 
        currentUser.taps_today += 1;
        updateUI(); // Optimistic UI update

        const plusOne = document.createElement('div');
        plusOne.textContent = '+1';
        plusOne.classList.add('floating-plus-one');
        const rect = animalToTap.getBoundingClientRect(); // Use animalToTap's rect
        const containerRect = animalContainer.getBoundingClientRect();
        plusOne.style.left = `${event.clientX - containerRect.left - 10}px`;
        plusOne.style.top = `${event.clientY - containerRect.top - 20}px`;
        animalContainer.appendChild(plusOne);
        tg.HapticFeedback.impactOccurred('light');
        setTimeout(() => plusOne.remove(), 1000);

        const result = await apiCall('tap');
        if (result && result.success && result.user) {
            currentUser = result.user; // Sync with server state
            updateUI(); // Full update
        } else {
            // Revert optimistic update or just show error and rely on next full sync
            showMessage(result ? result.message : 'Tap failed to register.', 'error', 'tap-message');
            // Silently refetch user data to correct optimistic updates if tap failed
            const freshUserData = await apiCall('getUserData');
            if (freshUserData && freshUserData.success && freshUserData.user) {
                currentUser = freshUserData.user;
                updateUI();
            }
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
                </div>
            `;
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
                showMessage(`Opened ${task.title}. Return to app and task may auto-claim or click again if needed.`, 'info', 'task-message');
                
                // Optional: Add a small delay to allow user to switch apps and potentially join
                // For now, we try to claim, backend will verify.
                // A better UX is to have a "Verify" button after they return.
                // For this fix, we'll make the claim more robust.

                // Attempt to claim after a short delay (e.g., 2 seconds) or rely on user clicking again
                // This timeout is just to give a slight buffer. The real check is on the server.
                setTimeout(async () => {
                    // Re-check button state in case another process completed it.
                    const currentCompletedMask = currentUser.daily_tasks_completed_mask || 0;
                    if ((currentCompletedMask & (1 << (taskId - 1))) > 0) {
                        showMessage(`Task "${task.title}" already marked complete.`, 'info', 'task-message');
                        renderTasks(); // Re-render to update button state
                        return;
                    }

                    const result = await apiCall('claimTask', { task_id: taskId });
                    if (result && result.success && result.user) {
                        currentUser = result.user;
                        updateUI(); // This will re-render tasks with new completed state
                        showMessage(`Task "${task.title}" completed! +${result.points_earned || task.reward} points.`, 'success', 'task-message');
                        tg.HapticFeedback.notificationOccurred('success');
                    } else {
                        showMessage(result ? result.message : 'Failed to claim task. Try again or ensure you joined.', 'error', 'task-message');
                        // Re-enable button if failed and not already completed by another means
                        if (!((currentUser.daily_tasks_completed_mask || 0) & (1 << (taskId - 1)))) {
                           claimButton.disabled = false;
                           claimButton.textContent = 'Join & Claim';
                        }
                    }
                }, 1500); // Reduced delay, or make it configurable
            });
        });
    }

    function checkAdButtonState() {
        if (!currentUser || !watchAdButton) return;
        const adsLimitReached = currentUser.ads_watched_today >= 45;
        let cooldownActive = false;
        let remainingCooldown = 0;

        if (currentUser.last_ad_watched_timestamp) {
            const lastAdTime = new Date(currentUser.last_ad_watched_timestamp).getTime();
            const now = new Date().getTime();
            const cooldownMillis = 3 * 60 * 1000; // 3 minutes
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
        watchAdButton.disabled = true; // Disable button immediately

        if (typeof show_9338274 === 'function') {
            show_9338274() // For Rewarded Interstitial. For Popup: show_9338274('pop')
                .then(async () => {
                    adStatusMessage.textContent = 'Ad completed! Claiming reward...';
                    const result = await apiCall('watchAd');
                    if (result && result.success && result.user) {
                        currentUser = result.user; // CRITICAL: Update currentUser
                        updateUI(); // CRITICAL: Update UI based on new currentUser
                        showMessage(`+${result.points_earned || 40} points!`, 'success', 'ad-status-message');
                        tg.HapticFeedback.notificationOccurred('success');
                    } else {
                        showMessage(result ? result.message : 'Failed to claim ad reward.', 'error', 'ad-status-message');
                        // If failed, re-fetch user data to be safe
                        const freshUserData = await apiCall('getUserData');
                        if (freshUserData && freshUserData.success && freshUserData.user) currentUser = freshUserData.user;
                        updateUI(); // Update UI even on failure to reflect accurate state
                    }
                    // checkAdButtonState() will be called by updateUI implicitly
                })
                .catch(e => {
                    console.error('Monetag ad error:', e);
                    showMessage('Ad error or closed early. No reward.', 'error', 'ad-status-message');
                    checkAdButtonState(); // Re-check button state
                });
        } else {
            showMessage('Ad SDK not available.', 'error', 'ad-status-message');
            checkAdButtonState(); // Re-check button state
        }
    });

    withdrawButtons.forEach(button => {
        button.addEventListener('click', () => {
            if (!currentUser) return;
            const pointsNeeded = parseInt(button.dataset.points);
            if (currentUser.points < pointsNeeded) {
                showMessage(`Not enough points. You need ${pointsNeeded.toLocaleString()}.`, 'error', 'withdraw-message');
                tg.HapticFeedback.notificationOccurred('error');
                return;
            }
            selectedWithdrawal = {
                points: pointsNeeded,
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
            points_to_withdraw: selectedWithdrawal.points,
            method: method,
            wallet_address_or_id: addressOrId
        });

        if (result && result.success && result.user) {
            currentUser = result.user;
            updateUI();
            showMessage(`Withdrawal request for ${selectedWithdrawal.points.toLocaleString()} points submitted!`, 'success', 'withdraw-message');
            tg.HapticFeedback.notificationOccurred('success');
            withdrawForm.classList.add('hidden');
            selectedWithdrawal = null;
            fetchWithdrawalHistory();
        } else {
            showMessage(result ? result.message : 'Withdrawal request failed.', 'error', 'withdraw-message');
        }
        submitWithdrawalButton.disabled = false;
        submitWithdrawalButton.textContent = 'Submit Withdrawal';
    });

    async function fetchWithdrawalHistory() {
        if (!currentUser) return;
        withdrawalHistoryList.innerHTML = '<li>Loading history...</li>'; // Show loading state
        const result = await apiCall('getWithdrawalHistory');
        withdrawalHistoryList.innerHTML = ''; // Clear
        if (result && result.success && result.history) {
            if (result.history.length === 0) {
                withdrawalHistoryList.innerHTML = '<li>No withdrawal history.</li>';
            } else {
                result.history.forEach(item => {
                    const li = document.createElement('li');
                    li.textContent = `${new Date(item.requested_at).toLocaleString()}: ${item.points_withdrawn.toLocaleString()} points to ${item.method} (${item.wallet_address_or_id.substring(0,10)}...) - Status: ${item.status}`;
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
            document.execCommand('copy');
            showMessage('Referral link copied!', 'success', 'profile-message');
            tg.HapticFeedback.notificationOccurred('success');
        } catch (err) {
            showMessage('Failed to copy link.', 'error', 'profile-message');
            console.error('Copy failed:', err);
        }
        window.getSelection().removeAllRanges(); // Deselect
    });

    initializeApp();
});
