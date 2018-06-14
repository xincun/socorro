from django.contrib import admin
from django.contrib.admin import sites


class SocorroAdminSite(sites.AdminSite):
    index_template = 'admin/socorro_admin_index.html'


site = SocorroAdminSite()

# Stomp on the myriad of places Django stashes their AdminSite instance so
# registering works with ours
admin.site = site
sites.site = site

# Autodiscover all the admin modules and pull in models and such
admin.autodiscover_modules('admin', register_to=site)
