// app/controllers/parentController.js
app.controller('parentController', function ($scope, $http, $location, formatDateForMySQL) {
    $scope.data = {};
    $scope.activeTab = 'progress';
    $scope.newAppointment = {};
    $scope.newChild = {};
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
            // 2. Remove csrfStore.clear() - browser handles cookie cleanup
            $location.path('/login');
        }).catch(function (err) {
            handleError(err, 'Logout failed.');
        });
    };

    var chartTimer = null;

    $scope.loadDashboard = function () {
        $http.get('api/dashboard.php').then(function (response) {
            if (response.data.status === 'success') {
                $scope.data = response.data.data;
                $scope.loading = false;
                handleSuccess('All child progress data refreshed!'); // ADD THIS
                if ($scope.data.students) {
                    if (chartTimer) clearTimeout(chartTimer);
                    chartTimer = setTimeout(function () {
                        $scope.data.students.forEach(function (student) {
                            if (student.performance_history && student.performance_history.length > 0) {
                                renderParentChart(student.student_id, student.performance_history);
                            }
                        });
                    }, 200);
                }
            } else {
                $scope.loading = false;
                handleError(response, 'Unable to load parent dashboard.');
            }
        }).catch(function (err) {
            $scope.loading = false;
            handleError(err, 'Unable to load parent dashboard.');
        });
    };

    window.parentCharts = {};

    function renderParentChart(studentId, history) {
        const ctx = document.getElementById('perfChart_' + studentId);
        if (!ctx) return;
        if (window.parentCharts[studentId]) window.parentCharts[studentId].destroy();

        const labels = [...new Set(history.map(h => h.recorded_at.split(' ')[0]))];
        const mentors = {};
        history.forEach(h => {
            const mName = h.mentor_name || 'System';
            if (!mentors[mName]) mentors[mName] = { gpa: [], att: [] };
            mentors[mName].gpa.push({ date: h.recorded_at.split(' ')[0], val: parseFloat(h.gpa) });
            mentors[mName].att.push({ date: h.recorded_at.split(' ')[0], val: parseFloat(h.attendance) });
        });

        const datasets = [];
        const colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899'];
        let colorIdx = 0;

        Object.keys(mentors).forEach(mName => {
            const color = colors[colorIdx % colors.length];

            const gpaData = labels.map(label => {
                const record = mentors[mName].gpa.find(r => r.date === label);
                return record ? record.val : null;
            });
            const attData = labels.map(label => {
                const record = mentors[mName].att.find(r => r.date === label);
                return record ? record.val : null;
            });

            datasets.push({
                label: 'GPA (' + mName + ')', data: gpaData, borderColor: color,
                yAxisID: 'y', tension: 0.4, spanGaps: true
            });
            datasets.push({
                label: 'Attendance % (' + mName + ')', data: attData, borderColor: color,
                yAxisID: 'y1', tension: 0.4, borderDash: [5, 5], spanGaps: true
            });
            colorIdx++;
        });

        window.parentCharts[studentId] = new Chart(ctx, {
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

    $scope.setTab = function (tabName) {
        $scope.activeTab = tabName;
    };

    $scope.requestAppointment = function () {
        if (!$scope.newAppointment.student_id || !$scope.newAppointment.mentor_id || !$scope.newAppointment.requested_time) {
            handleError(null, 'Please select a child, mentor, and date/time.');
            return;
        }
        var payload = angular.copy($scope.newAppointment);
        payload.requested_time = formatDateForMySQL(payload.requested_time);
        $http.post('api/actions.php?action=request_appointment', payload).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Appointment requested!');
                $scope.newAppointment = {};
                $scope.loadDashboard();
            } else {
                handleError(res, 'Failed to request appointment.');
            }
        }).catch(function (err) {
            handleError(err, 'Failed to request appointment.');
        });
    };

    $scope.rescheduleAppointment = function (apt) {
        if (!apt.new_time) {
            handleError(null, 'Please select a new date/time.');
            return;
        }
        $http.post('api/actions.php?action=reschedule_appointment', {
            appointment_id: apt.appointment_id,
            student_id: apt.student_id,
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
            appointment_id: apt.appointment_id,
            student_id: apt.student_id
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
                handleError(res, 'Unable to update notification status.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to update notification status.');
        });
    };

    $scope.requestParentLink = function () {
        if (!$scope.newChild.student_email || !$scope.newChild.connection_code) {
            handleError(null, "Please provide your child's email and connection code.");
            return;
        }
        $http.post('api/actions.php?action=request_parent_link', {
            student_email: $scope.newChild.student_email,
            connection_code: $scope.newChild.connection_code // Pass the code to the backend
        }).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Connection request sent to your child.');
                $scope.newChild = {};
                $scope.loadDashboard();
            } else {
                handleError(res, res.data.message || 'Unable to send connection request.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to send connection request.');
        });
    };

    $scope.requestConsentChange = function (studentId, changes) {
        if (!changes || !changes.trim()) {
            handleError(null, 'Please describe what changes you are requesting.');
            return;
        }
        $http.post('api/actions.php?action=request_consent_change', { student_id: studentId, changes: changes }).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Consent change request sent to student.');
                $scope.loadDashboard();
            } else {
                handleError(res, 'Failed to send request.');
            }
        }).catch(function (err) {
            handleError(err, 'Failed to send request.');
        });
    };

    $scope.acknowledgeEscalation = function (escalation) {
        $http.post('api/actions.php?action=acknowledge_escalation', {
            escalation_id: escalation.escalation_id
        }).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Escalation acknowledged.');
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to acknowledge escalation.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to acknowledge escalation.');
        });
    };

    $scope.loadDashboard();

});
