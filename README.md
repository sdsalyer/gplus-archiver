# gplus-archiver

A tool for exporting G+ data. Currently only working for Community content. 
It's recommended to export your personal data using the 
[Google Takeout](https://takeout.google.com/settings/takeout)
tool.

**Note:** the tool is very much a work-in-progress and currently incomplete, but 
I hope to have it polished enough to be widely applicable in the coming months 
as the Google+ death clock continues ticking.

### TODO

- Create subdirectories with community ID
  - parse community name out of "access" "description", e.g. "Immersive Imaginative Education (IIE) (Ideas)"
  - write to a file in the directory?
  - e.g. - archive/communityId/json
         - archive/communityId/otherFormat
- Retrieve comments from the API
- Retrieve attachments from the API

## Usage

Simply enter the URL for your Community and press the "Archive It!" button. 
The tool strips out the community ID from the URL and uses it to query the 
Google+ API and extract all the public posts within.

If the community has previously been archived, the resultant export is made 
available for download on the index page. 

### Limitations

The Google API does enforce query limits, which reset at midnight PST, so it is 
possible to cap out on API calls.

### How it works

The [Google+ "Activities" REST API](https://developers.google.com/+/web/api/rest/latest/activities/search) 
is queried  with an "in:" search string (this also works in the Google+ search bar 
to search within a community). The result set is limited to 20 "activities" at a time, 
so a `nextPageToken` is returned to allow for leafing through results, which are then 
exported as JSON data and zipped up for easy download.

**Example:**

- The Lone Wolf Roleplaying community url is: https://plus.google.com/communities/116965157741523529510
- The community ID is the number after the last forward slash, ie. **116965157741523529510**
- The Google+ API query string would then be: `in:116965157741523529510`

#### Changing how it works

It should be relatively simply to use any other query strings for different types of searches.
The below information could be useful for this.
for sharing this information). Depending on the search query used, the code may need to be modified to work on Comments or People 
objects instead of Activities.

**Google+ Search Queries**

Credit to [+Thomas Unterstenhoefer](https://plus.google.com/+ThomasUnterstenhoefer/posts/Trva48zGGrh) for this information. 

>> The Operators and the Syntax
>> 
>> from
>> Find posts from a specific user or user ID.
>> 
>> The ID, or url_id, is the string of numbers at the end of a profile’s URL.
>> 
>> Examples:
>> from:me
>> from:google
>> from:116431277733031496736
>> 
>> has
>> Find posts with specific content.
>> 
>> Examples:
>> has:attachment
>> has:video
>> has:photo
>> has:doc
>> has:slides
>> has:spreadsheet
>> has:poll
>> 
>> before & after
>> Find posts posted before or after a certain date.
>> 
>> Date formats are yyyy/mm/dd or yyyy-mm-dd
>> 
>> Examples:
>> before: 2015/3/14
>> after: 2014-03-14
>> 
>> commenter
>> Find posts commented on by a specific user.
>> 
>> Examples:
>> commenter:me
>> commenter:116431277733031496736
>> 
>> mention
>> Find posts that mention a specific user.
>> 
>> Examples:
>> mention:me
>> mention:116431277733031496736
>> 
>> in/community/collection
>> Find posts in any community or collection, or in a specific community or collection by ID.
>> 
>> The community or collection ID is the string of numbers and letters at the end of a community or collection’s URL.
>> 
>> Examples:
>> in:community
>> in:collection
>> in:107259666512014228221
>> in:s5DaIE
>> community:107259666512014228221
>> collection:s5DaIE
>> 
>> AND, OR & NOT
>> You can refine your search to include multiple queries or exclude certain terms by using logic operators. Enter these operators in all capital letters.
>> 
>> You can search for multiple operators without use of the word „AND.“ You can also replace „NOT“ with a minus sign.
>> 
>> Examples:
>> from:google AND from:cloud
>> from:google from:home has:video
>> has:doc OR has:ppt OR has:spreadsheet
>> from:116899029375914044550 NOT has:attachment
>> 
>> Source
>> https://support.google.com/plus/answer/1669519
>> 
>> UPDATE 2018-10-13
>> And wow, the brought a useful UI to the iOS app
>> https://plus.google.com/+ThomasUnterstenhoefer/posts/Q1zGnr2cLKr>> 



## Installation

The tool is a PHP web app using the [Slim](https://www.slimframework.com/) micro-framework to 
leverage the [Google API PHP Client](https://github.com/googleapis/google-api-php-client).
Dependencies are managed by [Composer](https://getcomposer.org/). Templates are powered by 
[Twig](https://twig.symfony.com/) template engine and [Pure CSS](https://purecss.io/).

**Installation Steps:**
1. Obtain Google+ API or OAUTH credentials from the [Google API Console](https://console.cloud.google.com/apis/)
2. Clone this repository
2. Rename `.\lib\constants.php` to `.\lib\secret.php` or update the `composer.json` file 
   to replace the `autoload` files as necessary.
3. Configure the constants in the file from item 2:
    - If using an API key, define `GAPI_API_KEY`
    - If using OAUTH, define both `GAPI_CLIENT_ID` and `GAPI_CLIENT_SECRET`
    - Finally, configure the path to the `ARCHIVE_DIRECTORY`
4. Run `composer install` to install the dependencies.
5. Configure your web server to point to the `.\public` directory as the 
    document root.
    - An Apache `.htaccess` file is included to allow for "pretty" URLs

## Contributing

I'm no PHP expert by any means and I do have a day job, a family, and other responsibilities, 
but I hope to maintain this tool well enough to get everyone's information exported before 
the Google+ shutdown.

If you have ideas for improvements or notice issues in the code, please feel free to contact 
me and submit a pull request. If you wish to fork the code and run with it, please do so by 
all means and be sure to view the LICENSE.md included in this repository.

## About

I have been an avid G+'er for about 1.5 years after discovering a vibrant tabletop RPG 
gaming community there. The announcement of Google+'s demise sparked a flurry of activity 
and concerns over losing the fantasic repository of ideas and information  contained within 
these various G+ communities and no apparent or easy way to export this information. As 
such, I set to work on creating this tool to help preserve this wealth of information. 

Thanks, and I hope someone finds this thing useful.