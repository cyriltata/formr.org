<h3>API</h3>

<p>
The formr API is the primary way to get data/results out of the platform. It's a low-level HTTP-based API that you can use principally to get results of a 
study for specified participants (sessions)
</p>
<p>
Resource requests to the formr API require that you have a valid access token which you can obtain by providing API credentials (a client id and a client 
secret)
</p>

<p>
API base URL: <br />
<code class="php">https://api.formr.org</code>
</p>


<h4>Obtaining Client ID and Client Secret</h4>
<p>
Access to the API is restricted, so only the administrators of formr are able to provide API credentials to formr users. To 
obtain API credentials, send an email to <a title=" We're excited to have people try this out, so you'll get a test account, if you're human or at least cetacean. But let us know a little about what you plan to do." class="schmail" href="mailto:IMNOTSENDINGSPAMTOcyril.tata@that-big-googly-eyed-email-provider.com">Cyril</a> and your credentials will be sent to you.
</p>

<h4>Obtaining An Access Token</h4>
<p>
An access token is an opaque string that identifies a formr user and can be used to make API calls without further authentication. formr API access tokens 
are short-lived and have a life span of about an hour.
</p>
	
<p>To generate an access token you need to make an HTTP POST request to the token endpoint of the API</p>

<pre>
<code class="http">
POST /oauth/access_token?
     client_id={client-id}
    &amp;client_secret={client-secret}
    &amp;grant_type=client_credentials
</code>
</pre>

<p>
This call will return a JSON object containing an access token which can be used to access the API without further authentication required.
<br />
Sample successful response
</p>

<pre>
<code class="json">
{
	"access_token":"XXXXXX3635f0dc13d563504b4d",
	"expires_in":3600,
	"token_type":"Bearer",
	"scope":null
}
</code>
</pre>

The attribute <code>expires_in</code> indicates the number of seconds for which is token is valid from its time of creation. If there is an error for example if the 
client details are not correct, then an error object is returned containing an error_description and sometimes an error_uri where you can read more about the 
generated error. An example of an error object is

<pre>
<code class="json">
{
	"error":"invalid_client",
	"error_description":"The client credentials are invalid"
}
</code>
</pre>


<h4>Making Resource Requests using generated access token</h4>

With the generated access token, you are able to make requests to the resource endpoints of the formr API. For now only the results resource endpoint has 
been implemented

<h5>Getting study results over the API</h5>

<h6> REQUEST </h6>
To obtain the results of a particular session in a particular run, send a GET HTTP request to the get endpoint along side the access_token obtained above 
and a request object. A request object in this case MUST be a JSON formatted string and passed using a parameter name called "request". For example

<pre>
<code class="http">
GET /get/results?
     access_token={access-token}
    &amp;request={json-string-representing-request}
</code>
</pre>

<p>The JSON string representing the "request" object for the GET request has to be of the following format</p>

<pre>
<code class="json">
{
	"run": {
		"name":  "run name",
		"sessions": ["sdaswew434df", "fdgdfg4323"],
		"surveys": [{
			"name": "survey_1",
			"items": ["survey_1_item_1", "survey_1_item_2", "survey_1_item_3"]
		},
		{
			"name": "survey_2",
			"items": ["survey_2_item_1", "survey_2_item_2", "survey_2_item_3"]
		}]
	}
}
</code>
</pre>

<p>
Parameters of the run object: <br /><br />

<ul>
	<li><b>name (required):</b> This should be name name of the run as shown on formr.org</li>

	<li><b>sessions (required):</b> An array of strings representing the sessions of interest whose results will be returned. At the moment, the API does not support 
returning the results of all the sessions in the run in one request but this might be implemented in the future. A single session can also be specified 
with the <code>session</code> parameter which MUST be a string an not an array of strings.</li>

	<li><b>surveys (optional):</b> An array of objects. Each object represents a survey in the run that will be included in the results set. If no survey(s) is(are) 
		specified, all the surveys in the run will be included in the results set. A single survey can be specified by using the parameter name <code>survey</code> which must be an object and not an array of one object. <br />
		Each survey object can have the following attributes:
		<ul>
			<li><b>name:</b> The name of the survey as represented in formr.org</li>
			<li><b>items:</b> An array of items in the survey that should be included in the results set. If this is not specified, all the items in the survey will be 
included in the results set which is generally not recommended.</li>
		</ul>
		
	</li>

</ul>
</p>

<h6>RESPONSE</h6>

<p>
The response to a results request is a JSON object. The keys of this JSON structure are the names of the survey that were indicated in the requested and the value associated to each survey entry is an array of objects representing the results collected for that survey for all the requested sessions. An example of a response object could be the following:
</p>

<pre>
<code class="json">
{
	"survey_1": [{
		"session": "sdaswew434df",
		"survey_1_item_1": "answer1",
		"survey_1_item_2": "answer2",
		"survey_1_item_2": "answer3",
	},
	{
		"session": "fdgdfg4323",
		"survey_1_item_1": "answer4",
		"survey_1_item_2": "answer5",
		"survey_1_item_2": "answer6",
	}],
	"survey_2": [........]
}
</code>
</pre>

<h4>Using the formr API in R</h4>

<pre><code class="r"># install formr library, you only need to do this once and for updates
devtools::install_github("rubenarslan/formr")
# load the library to the R work space, you need to do this each session
library(formr)
# connect to the API with your client_id and client_secret
# you only need to do this once per session if you don't need longer than one hour
formr_api_access_token(client_id = "your_id", client_secret = "your_secret" )
# To get the results row for a specific user, do the following
results = formr_api_results(list(
	run = list(
	   name = "rotation",  # for which run do you want results
	   session = "joyousCoyoteXXXLk5ByctNPryS4k-5JqZJYE19HwFhPu4FFk8beIHoBtyWniv46", # and for which user
	   surveys = list(
	   	list(
	   	name = "rotation_exercise", # for which survey
	   	items = list("exercise_1", "exercise_2") # and for which items
	   	)
	   )
	 )
	)
# Now you can e.g. do:
rotex = results$rotation_exercise
rotex[, c("exercise_1","exercise_2")]
# to read the documentation in the R package, do e.g.
?formr_api_results
</code>
</pre>
