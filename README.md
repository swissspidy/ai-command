# WP-CLI as an MCP Host

This repository is for the [CloudFest Hackathon 2025 project](https://hackathon.cloudfest.com/project/wp-cli-mcp-host/) to implement the [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) in the WordPress ecosystem, specifically integrating it with WP-CLI.

The core innovation is transforming WordPress into an MCP Server and WP-CLI into an MCP Host through a new package, enabling direct AI interactions with WordPress installations during development. This approach provides developers with powerful AI capabilities without requiring a live site or REST API endpoints.

**WordPress MCP Server Layer:**

1. Implementation of MCP Server interfaces in WordPress
2. Resource providers for posts, pages, media, and other WordPress content types
3. Tool definitions for common WordPress actions (content creation, media handling)
4. Context providers for WordPress configuration and site state

**WP-CLI MCP Host Package:**

1. MCP Host implementation within WP-CLI framework
2. New command namespace for AI operations
3. Integration with (local and remote) LLM providers
4. Transport layer for local WordPress communication

You can think of MCP as the "USB port for LLMs", a standard way for LLMs to interact with any third-party system using things like function calling.

While the Hackathon project focuses on WP-CLI, the _MCP Server_ is usage-agnostic. It could also be exposed via HTTP or so in the future.

The _MCP Host_, gets information (such as list of available tools) from the server and passes it on to the LLM (e.g. Gemini).

## Installing

Installing this package requires WP-CLI v2.5 or greater. Update to the latest stable release with `wp cli update`.

Once you've done so, you can install the latest stable version of this package with:

```bash
wp package install swissspidy/ai-command:@stable
```

To install the latest development version of this package, use the following command instead:

```bash
wp package install swissspidy/ai-command:dev-main
```

## Connections

Create a `ai-command.json` file in  your project root with the following information:

```
{
    "npx_path": "/usr/local/bin/npx",
    "filesystem_path": "/path/to/readable/writeable/directory"
}
```

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising our documentation.

For a more thorough introduction, [check out WP-CLI's guide to contributing](https://make.wordpress.org/cli/handbook/contributing/). This package follows those policy and guidelines.

### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should [search existing issues](https://github.com/swissspidy/ai-command/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/swissspidy/ai-command/issues/new). Include as much detail as you can, and clear steps to reproduce if possible. For more guidance, [review our bug report documentation](https://make.wordpress.org/cli/handbook/bug-reports/).

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/swissspidy/ai-command/issues/new) to discuss whether the feature is a good fit for the project.

Once you've decided to commit the time to seeing your pull request through, [please follow our guidelines for creating a pull request](https://make.wordpress.org/cli/handbook/pull-requests/) to make sure it's a pleasant experience. See "[Setting up](https://make.wordpress.org/cli/handbook/pull-requests/#setting-up)" for details specific to working on this package locally.

## Support

GitHub issues aren't for general support questions, but there are other venues you can try: https://wp-cli.org/#support


