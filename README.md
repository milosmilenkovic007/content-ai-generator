# AI Blog Post Generator

A WordPress plugin that automatically generates blog posts using OpenAI's Chat API based on custom prompts.

## Features

- Automatic blog post generation using OpenAI Chat API
- Customizable prompts for different post elements (title, content, excerpt, tags, featured image)
- Configurable post status (draft or published)
- Scheduled generation with customizable intervals (hourly, daily, weekly)
- Category assignment for generated posts
- Featured image generation (placeholder implementation)

## Installation

1. Upload the `ai-blog-post-generator` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'AI Blog Generator' in the admin menu to configure settings

## Configuration

### Settings
- **OpenAI API Key**: Enter your OpenAI API key
- **Model**: Choose the AI model (GPT-3.5 Turbo, GPT-4, GPT-4 Turbo)
- **Post Status**: Set whether generated posts should be drafts or published
- **Generation Interval**: How often to generate new posts
- **Enable Generator**: Toggle automatic generation on/off

### Prompts
Configure custom prompts for each post element:
- **Title Prompt**: Prompt for generating post titles
- **Content Prompt**: Prompt for generating post content
- **Excerpt Prompt**: Prompt for generating post excerpts
- **Tags Prompt**: Prompt for generating comma-separated tags
- **Featured Image Prompt**: Prompt for generating image descriptions
- **Category**: Select the category for generated posts

## Usage

1. Configure your OpenAI API key in the Settings page
2. Set up your prompts in the Prompts page
3. Choose your preferred model and post settings
4. Enable the generator
5. Posts will be automatically generated according to your schedule

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Valid OpenAI API key

## Notes

- Featured image generation currently creates placeholder images. For real image generation, integrate with DALL-E API.
- Make sure your prompts are clear and specific for best results.
- Monitor your OpenAI API usage and costs.

## License

GPL v2 or later