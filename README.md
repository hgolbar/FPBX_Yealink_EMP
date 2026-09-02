# FPBX_Yealink_EMP
Freepbx Endpoint Manager for Yealink Phones

As this is not a Sangoma or a commercial module, you will get an unsigned module warning telling you to remove it.  To disable the warning in SSH, run: "fwconsole setting SIGNATURECHECK 0".  This can also be done in the gui under Settings -> Advanced Settings -> "Enable Module Signature Checking" -> No (if you don't see it you need to set "Display Readonly Settings" and "Override Readonly Settings" to yes)

Currently only tested with legacy T28P phones with V.73 firmware. Should work with newer yealink phones, but untested.

This EPM will install a new module under the settings menu called Yealink Endpoint Manager. It has all the settings I normally fill in on configs.  
On the Global Settings tab, it should automatically fill in your server ip or FQDN and pull your time zone/set ntp to the pbx.  The dial now rules get pulled from your outbound routs, but only the one named "Outbound".  This will create a y000000000000.cfg for all the Yealink phones provisioned.  You can add custom global configs in the field labeled Global Custom Keys. 

On the template manager tab, it will automatically pull the SIP port from what's on the sip settings UDP listening port.  Clicking your phone model number should auto expand the  Line/Memory keys for that model phone.  Line key1  is greyed out because that is reserved for the Extension number.  The pickup field can be filled in but if it's left empty it fills ** on saving the template. Ringtones and Logo/Wallpaper will get saved in /var/www/html/PhoneSettings/(logo or ringtones)/.  This module will not resize or convert images or audio to make them compatible with the phones. You can add custom settings that aren't listed in the page in the field labeled Template Custom Key / Value Additions. 

The device manager tab has a scan tool that will scan your xxx.xxx.xxx.xxx/24 (ip can be manually changed if searching another subnet) subnet for available Yealink MAC addresses and their IP's and you can add them and the template on that page. You can also manually add the MAC if you if a specific phone is not found. 

All MAC.cfg files, y000000000000.cfg, and templates are saved to the /tftpboot/ folder.  These can be browsed by going to https://PBX.IP/tftpboot and https://PBX.IP/PhoneSettings. The http port has been shifted to :83.
