app.controller('profileController', function($scope, $http, $location, csrfStore) {
    $scope.user = {};
    $scope.students = [];
    $scope.mentors = [];
    $scope.parents = [];
    $scope.loading = true;
    $scope.error = '';
    $scope.success = '';

    function clearFlash() {
        $scope.error = '';
        $scope.success = '';
    }

    function handleError(err, defaultMsg) {
        clearFlash();
        const msg = (err && err.data && err.data.message) || (err && err.statusText) || defaultMsg || 'Something went wrong.';
        $scope.error = msg;
    }

    function handleSuccess(message) {
        clearFlash();
        $scope.success = message;
    }

    $scope.logout = function() {
        $http.post('api/auth.php?action=logout').then(function() {
            csrfStore.clear();
            $location.path('/login');
        }).catch(function(err) {
            handleError(err, 'Logout failed.');
        });
    };

    $scope.goHome = function() {
        if ($scope.user && $scope.user.role_name) {
            $location.path('/' + $scope.user.role_name.toLowerCase());
        } else {
            $location.path('/login');
        }
    };

    $scope.loadProfile = function() {
        $scope.loading = true;
        $http.get('api/users.php?action=me').then(function(res) {
            if (res.data.status === 'success' && res.data.user) {
                $scope.user = res.data.user;
                $scope.loading = false;
                $scope.loadRoleData();
            } else {
                $scope.loading = false;
                handleError(res, 'Unable to load profile.');
            }
        }).catch(function(err) {
            $scope.loading = false;
            handleError(err, 'Unable to load profile.');
        });
    };

    $scope.updateProfile = function() {
        $http.post('api/users.php?action=update_profile', {
            name: $scope.user.name,
            email: $scope.user.email
        }).then(function(res) {
            if (res.data.status === 'success') {
                handleSuccess('Profile updated successfully.');
            } else {
                handleError(res, 'Unable to update profile.');
            }
        }).catch(function(err) {
            handleError(err, 'Unable to update profile.');
        });
    };

    $scope.loadRoleData = function() {
        if ($scope.user.role_name === 'Mentor' || $scope.user.role_name === 'Parent') {
            $http.get('api/students.php').then(function(res) {
                if (res.data.status === 'success') {
                    $scope.students = res.data.students || [];
                }
            }).catch(function() {});
        }
        if ($scope.user.role_name === 'Mentor') {
            $http.get('api/parents.php').then(function(res) {
                if (res.data.status === 'success') {
                    $scope.parents = res.data.parents || [];
                }
            }).catch(function() {});
        }
        if ($scope.user.role_name === 'Parent') {
            $http.get('api/mentors.php').then(function(res) {
                if (res.data.status === 'success') {
                    $scope.mentors = res.data.mentors || [];
                }
            }).catch(function() {});
        }
    };

    $scope.loadProfile();
});
