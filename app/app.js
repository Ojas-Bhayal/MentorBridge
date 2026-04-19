// app/app.js
const app = angular.module('MentorBridgeApp', ['ngRoute']);

// Utility: format JS Date or ISO string to MySQL TIMESTAMP format
function formatDateForMySQL(date) {
    if (!date) return '';
    if (typeof date === 'string') return date.replace('T', ' ').substring(0, 19);
    var d = new Date(date);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
        + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') + ':' + String(d.getSeconds()).padStart(2, '0');
}

function formatDateOnly(date) {
    if (!date) return '';
    if (typeof date === 'string') return date.substring(0, 10);
    return date.toISOString().split('T')[0];
}

// Make available as injectable constants
app.constant('formatDateForMySQL', formatDateForMySQL);
app.constant('formatDateOnly', formatDateOnly);

// Filter: humanize status strings (e.g. 'in_progress' -> 'in progress')
app.filter('humanize', function () {
    return function (input) {
        return input ? input.replace(/_/g, ' ') : '';
    };
});

app.config(function ($routeProvider) {
    var bust = '?v=6';
    $routeProvider
        .when('/login', {
            templateUrl: 'views/login.html' + bust,
            controller: 'authController'
        })
        .when('/signup', {
            templateUrl: 'views/signup.html' + bust,
            controller: 'signupController'
        })
        .when('/student', {
            templateUrl: 'views/student-dash.html' + bust,
            controller: 'studentController',
            resolve: { auth: checkAuth }
        })
        .when('/mentor', {
            templateUrl: 'views/mentor-dash.html' + bust,
            controller: 'mentorController',
            resolve: { auth: checkAuth }
        })
        .when('/parent', {
            templateUrl: 'views/parent-dash.html' + bust,
            controller: 'parentController',
            resolve: { auth: checkAuth }
        })
        .when('/profile', {
            templateUrl: 'views/profile.html' + bust,
            controller: 'profileController',
            resolve: { auth: checkAuth }
        })
        .otherwise({
            redirectTo: '/login'
        });
});

function checkAuth($q, $http, $location, csrfStore) {
    const deferred = $q.defer();
    $http.get('api/auth.php?action=check').then(function (response) {
        if (response.data.authenticated) {
            csrfStore.set(response.data.csrf_token);
            const path = $location.path();
            const rolePath = '/' + response.data.role.toLowerCase();

            if (path === '/profile' || path === rolePath) {
                deferred.resolve();
            } else {
                // If they go to wrong path but are authenticated, redirect to their role path
                $location.path(rolePath);
                deferred.reject();
            }
        } else {
            $location.path('/login');
            deferred.reject();
        }
    }).catch(function () {
        $location.path('/login');
        deferred.reject();
    });
    return deferred.promise;
}

// AFTER — in-memory only, cleared on page close
app.factory('csrfStore', function () {
    var _token = '';
    return {
        get: function () { return _token; },
        set: function (token) { if (token) _token = token; },
        clear: function () { _token = ''; }
    };
});

// Inside your app config in app/app.js
app.config(function ($httpProvider) {
    // 1. Enable automated CSRF cookie handling
    $httpProvider.defaults.xsrfCookieName = 'XSRF-TOKEN';
    $httpProvider.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

    // 2. Keep a simple interceptor only for handling 401/403 errors (Redirect to login)
    $httpProvider.interceptors.push(function ($q, $injector) {
        return {
            responseError: function (rejection) {
                if (rejection && (rejection.status === 401 || rejection.status === 403)) {
                    const $location = $injector.get('$location');
                    if ($location.path() !== '/login') {
                        $location.path('/login');
                    }
                }
                return $q.reject(rejection);
            }
        };
    });
});

// Note: Background session polling has been removed.
// Authentication is enforced by the route resolver on every page navigation,
// and the 1-hour idle timeout (session.gc_maxlifetime) will now work correctly.
