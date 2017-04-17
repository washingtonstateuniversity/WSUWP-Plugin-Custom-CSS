module.exports = function( grunt ) {
	grunt.initConfig( {
		pkg: grunt.file.readJSON( "package.json" ),

		concat: {
			code_mirror: {
				src: [
					"src/js/codemirror.js",
					"src/js/codemirror-css-mode.js"
				],
				dest: "src/js/codemirror-temp.js"
			}
		},

		uglify: {
			code_mirror: {
				src: "src/js/codemirror-temp.js",
				dest: "js/codemirror.min.js"
			}
		},

		clean: {
			options: {
				force: true
			},
			temp: [ "src/js/codemirror-temp.js" ]
		}

	} );

	grunt.loadNpmTasks( "grunt-contrib-concat" );
	grunt.loadNpmTasks( "grunt-contrib-clean" );
	grunt.loadNpmTasks( "grunt-contrib-uglify" );

	// Default task(s).
	grunt.registerTask( "default", [ "concat", "uglify", "clean" ] );
};
