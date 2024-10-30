=== Kramer ===
Tags: Technorati, Links, Cosmos, Pingback
Contributors: markjaquith, Nik
Stable tag: trunk
Requires at least: 2.0
Tested up to: 2.0.9

Kramer will add inbound links to a post on your weblog as pingbacks by checking Technorati and by monitoring incoming referrers.

== Description ==

Kramer will add inbound links to a post on your weblog as pingbacks without the need for the author of the post on blog A to send a ping to blog B by using both Technorati and by monitoring incoming referrers.

What this means is that pingback, trackback and other post-pinging tools are no longer required, as all links to a post will be found and shown as pingbacks.  To show links to the main weblog, or to other pages in general, a function called `kramer_inbound()` is provided. It can be used in Wordpress templates to display a list of the latest inbound links to that page.  It is usually included in the sidebar of a blog and can be fully configured via the Kramer administration interface.

== Installation ==

1. The zip archive contains two files, `README.txt` and `kramer.php`. Place kramer.php in your blogs wp-content/plugins/ directory.
2. Once there, log into your weblog's administration console, click on plugins and then activate the Kramer plugin. 
3. Click on Options then Kramer to view the Kramer options page
4. Under the API Key section, follow the link to Technorati to get an API key and insert the key into the field provided.
5. Your installation is now complete.  If there are incoming links to a post they should appear immediately.

== Support and Updates ==

To debug your Kramer install, view the HTML source on any page, and scroll down to the bottom where Kramer produces its debug output as a HTML comment. Within the debug output any errors are reported, as well as a trace of the request to Technorati and any comment actions.

To submit a bug, feature request or support ticket fill in the ticket form found at:

<http://dev.wp-plugins.org/newticket?component=kramer>

== Frequently Asked Questions ==

= How can this plugin help me? =

It will show every post linking to your posts, in the form of comments or pingbacks. The blog post linking to yours does not need to ping your post for the comment to be shown in your weblog. This means that by default you can carry out conversatios between blogs.

= Is there a limitation on using Technorati? =

Yes, the limit is 500 queries per day, per API key. It is recommended that the cache time is set to 4-6 hours.

= How does the cache work? =

The cache time is the time taken between reqests to Technorati for new inbound links for a post, for each post.

= How do can I include inbound links for a page in my sidebar? =

Simply edit your template, and at the position that you want the list of inbound links place `<?php kramer_inbound(); ?>`

== Contributors ==

* Nik Cubrilovic - original author
* Mark Jaquith - current maintainer
* Firas - testing, ideas
* Kevin Marks - support from Technorati and ideas

== License ==

Copyright 2005-2007  Nik Cubrilovic <nik@nik.com.au> and Mark Jaquith <mark.gpl@txfx.net>

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA