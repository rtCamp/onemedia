const wpConfig = require('@wordpress/prettier-config');

/**
 * @see https://prettier.io/docs/configuration
 * @type {import('prettier').Config}
 */
const config = {
	...wpConfig,
	overrides: [
		...(wpConfig.overrides || []),
		{
			files: '*.md',
			options: {
				tabWidth: 2,
				useTabs: false,
			},
		},
		{
			files: ['*.yml', '*.yaml'],
			options: {
				tabWidth: 2,
				useTabs: false,
				singleQuote: true,
			},
		},
	],
};

module.exports = config;
