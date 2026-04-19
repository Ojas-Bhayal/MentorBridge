// app/controllers/studentController.js
app.controller('studentController', function ($scope, $http, $location, csrfStore, formatDateForMySQL, formatDateOnly) {
    $scope.data = {};
    $scope.newGoal = {};
    $scope.newAppointment = {};
    $scope.loading = true;
    $scope.userName = '';
    $scope.minDateTime = new Date().toISOString().slice(0, 16);

    $scope.error = '';
    $scope.success = '';

    function clearFlash() {
        $scope.error = '';
        $scope.success = '';
    }

    function handleError(err, defaultMsg) {
        clearFlash();
        const msg = (err && err.data && err.data.message) || (err && err.statusText) || defaultMsg || 'Something went wrong. Please try again.';
        $scope.error = msg;
    }

    function handleSuccess(message) {
        clearFlash();
        $scope.success = message;
    }

    // Get user name from auth
    $http.get('api/auth.php?action=check').then(function (r) {
        if (r.data.authenticated) {
            $scope.userName = r.data.name;
        }
    });

    $scope.logout = function () {
        $http.post('api/auth.php?action=logout').then(function () {
            csrfStore.clear();
            $location.path('/login');
        }).catch(function (err) {
            handleError(err, 'Logout failed.');
        });
    };

    // In studentController.js
    var chartTimer = null;

    $scope.loadDashboard = function () {
        $http.get('api/dashboard.php').then(function (response) {
            if (response.data.status === 'success') {
                $scope.data = response.data.data;
                $scope.loading = false;
                handleSuccess('Your progress data is now up to date!'); // ADD THIS
                if ($scope.data.performance_history && $scope.data.performance_history.length > 0) {
                    if (chartTimer) clearTimeout(chartTimer);  // ADD THIS
                    chartTimer = setTimeout(function () {
                        renderStudentChart($scope.data.performance_history);
                    }, 200);
                }
            }
        });
    };

    function renderStudentChart(history) {
        const ctx = document.getElementById('performanceChart');
        if (!ctx) return;
        if (window.myPerfChart) window.myPerfChart.destroy();

        // 1. Extract unique dates for the shared X-axis
        const labels = [...new Set(history.map(h => h.recorded_at.split(' ')[0]))];

        // 2. Group records by mentor
        const mentors = {};
        history.forEach(h => {
            const mName = h.mentor_name || 'System';
            if (!mentors[mName]) mentors[mName] = { gpa: [], att: [] };
            mentors[mName].gpa.push({ date: h.recorded_at.split(' ')[0], val: parseFloat(h.gpa) });
            mentors[mName].att.push({ date: h.recorded_at.split(' ')[0], val: parseFloat(h.attendance) });
        });

        // 3. Build separate datasets for each mentor
        const datasets = [];
        const colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899'];
        let colorIdx = 0;

        Object.keys(mentors).forEach(mName => {
            const color = colors[colorIdx % colors.length];

            // Map values to aligned dates, insert nulls where missing
            const gpaData = labels.map(label => {
                const record = mentors[mName].gpa.find(r => r.date === label);
                return record ? record.val : null;
            });
            const attData = labels.map(label => {
                const record = mentors[mName].att.find(r => r.date === label);
                return record ? record.val : null;
            });

            datasets.push({
                label: 'GPA (' + mName + ')',
                data: gpaData,
                borderColor: color,
                yAxisID: 'y',
                tension: 0.4,
                spanGaps: true // Prevents line breaks on missing dates
            });
            datasets.push({
                label: 'Attendance % (' + mName + ')',
                data: attData,
                borderColor: color,
                yAxisID: 'y1',
                tension: 0.4,
                borderDash: [5, 5], // Dashed line for attendance to keep it legible
                spanGaps: true
            });

            colorIdx++;
        });

        window.myPerfChart = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    y: { type: 'linear', display: true, position: 'left', min: 0, max: 4.0 },
                    y1: { type: 'linear', display: true, position: 'right', min: 0, max: 100 }
                },
                plugins: { legend: { labels: { color: '#fff' } } }
            }
        });
    }

    $scope.updateConsent = function () {
        $http.post('api/actions.php?action=update_consent', $scope.data.consent).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Privacy settings saved!');
            } else {
                handleError(res, 'Unable to save privacy settings.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to save privacy settings.');
        });
    };
    // NEW: Actionable one-click consent approval
    $scope.approveConsentRequest = function (notif) {
        // Auto-enable the active privacy toggles
        $scope.data.consent.allow_session_notes = 1;
        $scope.data.consent.allow_feedback = 1;

        // Trigger the existing save function
        $scope.updateConsent();

        // Mark the notification as read to clear it from the pending list
        $scope.markNotification(notif, 'read');
    };
    $scope.addGoal = function () {
        var payload = angular.copy($scope.newGoal);
        if (payload.deadline instanceof Date) {
            payload.deadline = formatDateOnly(payload.deadline);
        }
        $http.post('api/actions.php?action=add_goal', payload).then(function (res) {
            if (res.data.status === 'success') {
                $scope.newGoal = {};
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to add goal.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to add goal.');
        });
    };

    $scope.updateGoalStatus = function (goal) {
        // 1. Logic Check: If already completed, don't send to server
        if (goal.status === 'completed') {
            handleSuccess('This task is already completed!');
            return;
        }

        // 2. Logic: Cycle only from pending -> in_progress -> completed
        let newStatus = goal.status === 'pending' ? 'in_progress' : 'completed';

        $http.post('api/actions.php?action=update_goal', {
            goal_id: goal.goal_id,
            status: newStatus
        }).then(function (res) {
            if (res.data.status === 'success') {
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to update goal status.');
            }
        }).catch(function (err) {
            // The interceptor in app.js will handle 401/403 errors (redirect to login)
            // This catch handles other connection or 500 errors
            handleError(err, 'Unable to update goal status.');
        });
    };

    $scope.editGoal = function (goal) {
        goal.editing = true;
        goal.editTitle = goal.title;
        goal.editDescription = goal.description || '';
    };

    $scope.cancelEditGoal = function (goal) {
        goal.editing = false;
    };

    $scope.saveGoalEdit = function (goal) {
        $http.post('api/actions.php?action=edit_goal', {
            goal_id: goal.goal_id,
            title: goal.editTitle,
            description: goal.editDescription
        }).then(function (res) {
            if (res.data.status === 'success') {
                goal.editing = false;
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to edit goal.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to edit goal.');
        });
    };

    // In studentController.js
    $scope.confirmDelete = function (goal) {
        goal.pendingDelete = true;
    };

    $scope.cancelDelete = function (goal) {
        goal.pendingDelete = false;
    };

    $scope.deleteGoal = function (goal) {
        goal.pendingDelete = false;
        $http.post('api/actions.php?action=delete_goal', { goal_id: goal.goal_id })
            .then(function (res) {
                if (res.data.status === 'success') $scope.loadDashboard();
                else handleError(res, 'Unable to delete goal.');
            }).catch(function (err) { handleError(err, 'Unable to delete goal.'); });
    };

    $scope.requestAppointment = function () {
        var payload = angular.copy($scope.newAppointment);
        payload.requested_time = formatDateForMySQL(payload.requested_time);
        $http.post('api/actions.php?action=request_appointment', payload).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Appointment requested!');
                $scope.newAppointment = {};
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to request appointment.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to request appointment.');
        });
    };

    $scope.rescheduleAppointment = function (apt) {
        if (!apt.new_time) {
            handleError(null, 'Please select a new date/time.');
            return;
        }
        $http.post('api/actions.php?action=reschedule_appointment', {
            appointment_id: apt.appointment_id,
            requested_time: formatDateForMySQL(apt.new_time)
        }).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Appointment successfully rescheduled.');
                $scope.loadDashboard();
            } else {
                handleError(res, 'Failed to reschedule appointment.');
            }
        }).catch(function (err) {
            handleError(err, 'Failed to reschedule appointment.');
        });
    };

    $scope.cancelAppointment = function (apt) {
        $http.post('api/actions.php?action=cancel_appointment', {
            appointment_id: apt.appointment_id
        }).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Appointment cancelled successfully.');
                $scope.loadDashboard();
            } else {
                handleError(res, 'Failed to cancel appointment.');
            }
        }).catch(function (err) {
            handleError(err, 'Failed to cancel appointment.');
        });
    };

    $scope.markNotification = function (notif, status) {
        $http.post('api/actions.php?action=update_notification_status', {
            notification_id: notif.id,
            status: status
        }).then(function (res) {
            if (res.data.status === 'success') {
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to update notification.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to update notification.');
        });
    };

    $scope.respondParentLink = function (request, decision) {
        $http.post('api/actions.php?action=respond_parent_link', {
            request_id: request.request_id,
            decision: decision
        }).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Parent connection ' + (decision === 'approve' ? 'approved' : 'denied') + '.');
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to update parent request.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to update parent request.');
        });
    };

    $scope.loadDashboard();
});
