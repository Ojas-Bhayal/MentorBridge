// app/controllers/authController.js
app.controller('authController', function($scope, $http, $location, csrfStore) {
    $scope.user = {};
    $scope.error = '';
    $scope.success = '';

    function clearFlash() {
        $scope.error = '';
        $scope.success = '';
    }

    function handleSuccess(message) {
        clearFlash();
        $scope.success = message;
    }

    $http.get('api/auth.php?action=check').then(function(response) {
        if (response.data.authenticated) {
            csrfStore.set(response.data.csrf_token);
            $location.path('/' + response.data.role.toLowerCase());
        }
    });

    $scope.login = function() {
        clearFlash();
        $http.post('api/auth.php?action=login', $scope.user)
            .then(function(response) {
                if (response.data.status === 'success') {
                    csrfStore.set(response.data.csrf_token);
                    handleSuccess('Login successful. Redirecting...');
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
