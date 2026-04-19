// app/controllers/mentorController.js
app.controller('mentorController', function ($scope, $http, $location, formatDateForMySQL, formatDateOnly) {
    $scope.data = {};
    $scope.activeTab = 'schedule';
    $scope.selectedStudent = null;
    $scope.newFeedback = {};
    $scope.newEscalation = { severity: 'Medium' };
    $scope.newSession = { type: 'confidential' };
    $scope.newReport = {};
    $scope.newPerformance = {};
    $scope.newBroadcast = { target: 'student' };
    $scope.loading = true;
    $scope.userName = '';
    $scope.minDateTime = new Date().toISOString().slice(0, 16);

    $scope.error = '';
    $scope.success = '';
    $scope.newLink = {};

    $scope.linkStudent = function () {
        if (!$scope.newLink.student_id) {
            handleError(null, 'Please select a student to add to your roster.');
            return;
        }

        $http.post('api/actions.php?action=link_student', { 
            student_id: $scope.newLink.student_id 
        }).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Student successfully added to your roster.');
                $scope.newLink = {}; // Reset the dropdown
                $scope.loadDashboard(); // Refreshes the roster list automatically
            } else {
                handleError(res, 'Unable to link student.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to link student.');
        });
    };
    
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
            // 2. Remove csrfStore.clear() - cookies are handled by the browser
            $location.path('/login');
        }).catch(function (err) {
            handleError(err, 'Logout failed.');
        });
    };

    $scope.loadDashboard = function () {
        $http.get('api/dashboard.php').then(function (response) {
            if (response.data.status === 'success') {
                $scope.data = response.data.data;
                $scope.loading = false;
                handleSuccess('Mentor dashboard updated!'); // ADD THIS
            } else {
                $scope.loading = false;
                handleError(response, 'Unable to load mentor dashboard.');
            }
        }).catch(function (err) {
            $scope.loading = false;
            handleError(err, 'Unable to load mentor dashboard.');
        });
    };

    // Filter functions for appointment sections
    $scope.pendingOrRescheduled = function (apt) {
        return apt.status === 'pending' || apt.status === 'rescheduled';
    };
    $scope.handledAppointment = function (apt) {
        return apt.status !== 'pending' && apt.status !== 'rescheduled';
    };

    $scope.selectStudent = function (student) {
        $scope.selectedStudent = student;
    };

    // 1. Unified Session Logic (Removes need for scheduleSession)
$scope.addSession = function () {
    if (!$scope.selectedStudent) {
        handleError(null, 'Please select a student first.');
        return;
    }

    var payload = {
        student_id: $scope.selectedStudent.student_id,
        scheduled_at: formatDateForMySQL($scope.newSession.scheduled_at),
        // Logic: Correctly sets 'parent' or 'confidential' for the database
        type: $scope.newSession.isParentShared ? 'parent' : 'confidential'
    };

    $http.post('api/actions.php?action=add_session', payload).then(function (res) {
        if (res.data.status === 'success') {
            $scope.newSession = { type: 'confidential', isParentShared: false };
            handleSuccess('Session scheduled successfully.');
            $scope.loadDashboard();
        } else {
            handleError(res, 'Unable to schedule session.');
        }
    }).catch(function (err) {
        handleError(err, 'Unable to schedule session.');
    });
};

// 2. Fixed Appointment Logic (Corrects the "Invalid Action" error)
$scope.updateAppointment = function (apt, action) {
    let payload = { appointment_id: apt.appointment_id };
    
    // If reschedule is chosen, use the new_time from the input field in the HTML
    if (action === 'reschedule') {
        if (!apt.new_time) {
            handleError(null, 'Please select a new time for rescheduling.');
            return;
        }
        payload.requested_time = formatDateForMySQL(apt.new_time);
    }

    // This now sends 'approve_appointment', 'reject_appointment', etc.
    $http.post('api/actions.php?action=' + action + '_appointment', payload)
        .then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Appointment ' + action + 'ed successfully.');
                $scope.loadDashboard(); 
            } else {
                handleError(res, 'Action failed.');
            }
        });
};

    $scope.addFeedback = function () {
        if (!$scope.selectedStudent) {
            handleError(null, 'Please select a student before sending feedback.');
            return;
        }
        $scope.newFeedback.student_id = $scope.selectedStudent.student_id;
        $http.post('api/actions.php?action=add_feedback', $scope.newFeedback).then(function (res) {
            if (res.data.status === 'success') {
                $scope.newFeedback = {};
                handleSuccess('Feedback sent successfully.');
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to send feedback.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to send feedback.');
        });
    };

    $scope.addEscalation = function () {
        if (!$scope.selectedStudent) {
            handleError(null, 'Please select a student before raising an escalation.');
            return;
        }
        $scope.newEscalation.student_id = $scope.selectedStudent.student_id;
        $http.post('api/actions.php?action=add_escalation', $scope.newEscalation).then(function (res) {
            if (res.data.status === 'success') {
                $scope.newEscalation = { severity: 'Medium' };
                handleSuccess('Escalation triggered.');
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to trigger escalation.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to trigger escalation.');
        });
    };

    $scope.completeSession = function (session) {
        if (!session.notes) {
            handleError(null, 'Please add notes before completing.');
            return;
        }
        $http.post('api/actions.php?action=complete_session', { session_id: session.session_id, notes: session.notes }).then(function (res) {
            if (res.data.status === 'success') {
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to complete session.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to complete session.');
        });
    };

    $scope.cancelSession = function (session) {
        $http.post('api/actions.php?action=cancel_session', { session_id: session.session_id }).then(function (res) {
            if (res.data.status === 'success') {
                $scope.loadDashboard();
            } else {
                handleError(res, 'Failed to cancel session.');
            }
        }).catch(function (err) {
            handleError(err, 'Failed to cancel session.');
        });
    };

    $scope.addReport = function () {
        if (!$scope.selectedStudent) {
            handleError(null, 'Please select a student before filing a report.');
            return;
        }
        var payload = angular.copy($scope.newReport);
        payload.student_id = $scope.selectedStudent.student_id;
        if (payload.month instanceof Date) {
            payload.month = formatDateOnly(payload.month);
        }
        // If file was uploaded, use that path; otherwise use the manually entered URL
        if ($scope.uploadedFilePath) {
            payload.file_path = $scope.uploadedFilePath;
        }
        $http.post('api/actions.php?action=add_report', payload).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Report filed!');
                $scope.newReport = {};
                $scope.uploadedFilePath = '';
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to file report.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to file report.');
        });
    };

    $scope.uploadedFilePath = '';
    $scope.uploadingFile = false;

    $scope.uploadReportFile = function (files) {
        if (!files || files.length === 0) return;
        var file = files[0];
        var formData = new FormData();
        formData.append('file', file);
        $scope.uploadingFile = true;
        $http.post('api/upload.php', formData, {
            transformRequest: angular.identity,
            headers: { 'Content-Type': undefined }
        }).then(function (res) {
            $scope.uploadingFile = false;
            if (res.data.status === 'success') {
                $scope.uploadedFilePath = res.data.file_path;
                $scope.newReport.file_path = res.data.file_path;
                handleSuccess('File uploaded successfully.');
            } else {
                handleError(res, 'File upload failed.');
            }
        }).catch(function (err) {
            $scope.uploadingFile = false;
            handleError(err, 'File upload failed.');
        });
    };

    $scope.addPerformance = function () {
        if (!$scope.selectedStudent) {
            handleError(null, 'Please select a student before updating performance.');
            return;
        }
        $scope.newPerformance.student_id = $scope.selectedStudent.student_id;
        $http.post('api/actions.php?action=add_performance', $scope.newPerformance).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Performance updated & Auto-engine calculated!');
                $scope.newPerformance = {};
                $scope.loadDashboard();
            } else {
                handleError(res, 'Unable to update performance.');
            }
        }).catch(function (err) {
            handleError(err, 'Unable to update performance.');
        });
    };

    $scope.broadcastMessage = function () {
        if (!$scope.selectedStudent) {
            handleError(null, 'Please select a student before sending a broadcast.');
            return;
        }

        let payload = { message: $scope.newBroadcast.message };
        payload.student_id = $scope.selectedStudent.student_id;
        payload.target = $scope.newBroadcast.target; // 'student' or 'parent'

        $http.post('api/actions.php?action=send_broadcast', payload).then(function (res) {
            if (res.data.status === 'success') {
                handleSuccess('Broadcast sent!');
                $scope.newBroadcast.message = '';
            } else {
                handleError(res, 'Broadcast failed.');
            }
        }).catch(function (err) {
            handleError(err, 'Broadcast failed.');
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

    $scope.loadDashboard();
});
