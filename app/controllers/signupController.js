// app/controllers/signupController.js
app.controller('signupController', function($scope, $http, $location, csrfStore) {
    $scope.user = { role_id: '' };
    $scope.defaultRoles = [
        { role_id: 1, role_name: 'Student' },
        { role_id: 2, role_name: 'Mentor' },
        { role_id: 3, role_name: 'Parent' }
    ];
    $scope.roles = angular.copy($scope.defaultRoles);
    $scope.error = '';
    $scope.success = '';

    function clearFlash() {
        $scope.error = '';
        $scope.success = '';
    }

    $scope.loadRoles = function() {
        $http.get('api/roles.php').then(function(response) {
            if (response.data.status === 'success' && Array.isArray(response.data.roles) && response.data.roles.length > 0) {
                $scope.roles = response.data.roles;
            }
        }).catch(function() {
            // keep fallback roles
        });
    };

    $scope.loadRoles();

    $scope.isMentorSelected = function() {
        var r = $scope.roles.find(x => x.role_id == $scope.user.role_id);
        return r && r.role_name === 'Mentor';
    };

    function handleSuccess(message) {
        clearFlash();
        $scope.success = message;
    }

    $scope.register = function() {
        clearFlash();
        $http.post('api/auth.php?action=register', $scope.user)
            .then(function(response) {
                if (response.data.status === 'success') {
                    csrfStore.set(response.data.csrf_token);
                    handleSuccess('Registration successful! Redirecting...');
                    $location.path('/' + response.data.role.toLowerCase());
                } else {
                    $scope.error = response.data.message;
                }
            })
            .catch(function(err) {
                if (err && err.data && err.data.message) {
                    $scope.error = err.data.message;
                } else {
                    $scope.error = "Error connecting to server.";
                }
            });
    };
});
